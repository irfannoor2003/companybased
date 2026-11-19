<?php

namespace Tests\Feature;

use App\Support\DumpSanitizer;
use Tests\TestCase;

/**
 * A dump from a MariaDB or GUI tool can include a generated column in its
 * INSERT statements, which MySQL refuses with ERROR 3105. Restoring a live
 * backup locally is exactly when that surfaces, so the cleaning is pinned here
 * rather than left to a manual edit of the .sql file.
 */
class DumpSanitizerTest extends TestCase
{
    private function dump(): string
    {
        return <<<'SQL'
CREATE TABLE `approval_requests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `comment` text,
  `live_step` int unsigned GENERATED ALWAYS AS ((case when (`status` = _utf8mb4'pending') then `step` else NULL end)) STORED,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB;

INSERT INTO `approval_requests` (`id`, `status`, `comment`, `live_step`) VALUES
(1, 'pending', 'A comment, with a comma', 1),
(2, 'approved', NULL, NULL);

INSERT INTO `users` (`id`, `name`) VALUES (1, 'Admin'), (2, 'Sarah');
SQL;
    }

    public function test_it_finds_the_generated_column_from_the_dumps_create_statement(): void
    {
        $this->assertSame(
            ['approval_requests' => ['live_step']],
            DumpSanitizer::generatedColumns($this->dump()),
        );
    }

    public function test_it_removes_the_generated_column_from_inserts_but_not_other_tables(): void
    {
        $clean = DumpSanitizer::strip($this->dump(), DumpSanitizer::generatedColumns($this->dump()));

        $this->assertStringContainsString(
            '`live_step` int unsigned GENERATED ALWAYS AS',
            $clean,
            'the CREATE TABLE definition must remain for MySQL to recompute the value',
        );
        $this->assertStringNotContainsString("`comment`, `live_step`) VALUES", $clean);
        $this->assertStringContainsString("'A comment, with a comma'", $clean, 'commas inside quoted values must survive');
        $this->assertStringContainsString('INSERT INTO `users` (`id`, `name`) VALUES (1, \'Admin\'), (2, \'Sarah\');', $clean);
        $this->assertStringContainsString('GENERATED ALWAYS AS', $clean);
    }

    public function test_it_leaves_a_dump_without_generated_columns_alone(): void
    {
        $dump = 'CREATE TABLE `users` (`id` int NOT NULL) ENGINE=InnoDB;'.PHP_EOL
            ."INSERT INTO `users` (`id`) VALUES (1);";

        $this->assertSame([], DumpSanitizer::generatedColumns($dump));
        $this->assertSame($dump, DumpSanitizer::strip($dump, []));
    }

    /**
     * mysqldump emits INSERTs with no column list by default. The generated
     * column is then the last value of every tuple, with no name to match, so
     * its position must come from the CREATE TABLE column order. This is the
     * exact shape of the live backup that failed with ERROR 3105.
     */
    public function test_it_handles_positional_inserts_with_no_column_list(): void
    {
        $dump = <<<'SQL'
CREATE TABLE `approval_requests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `approvable_type` varchar(255) NOT NULL,
  `step` int unsigned NOT NULL DEFAULT '1',
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `comment` text,
  `live_step` int unsigned GENERATED ALWAYS AS (case when `status` = 'pending' then `step` else NULL end) STORED,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB;

INSERT INTO `approval_requests` VALUES
(1,'ExpenseClaim',1,'approved','a, comma',NULL),
(2,'PurchaseOrder',2,'pending',NULL,2);
SQL;

        $generated = DumpSanitizer::generatedColumns($dump);
        $this->assertSame(['approval_requests' => ['live_step']], $generated);

        $clean = DumpSanitizer::strip($dump, $generated);

        // The generated value is gone from the tuples, but an explicit column
        // list now tells MySQL which column each remaining value belongs to.
        $this->assertStringContainsString('INSERT INTO `approval_requests` (`id`,`approvable_type`,`step`,`status`,`comment`) VALUES', $clean);
        $this->assertStringContainsString("'a, comma'", $clean, 'commas in quoted values must survive');
        $this->assertStringNotContainsString("'a, comma',NULL)", $clean);
        $this->assertStringContainsString('GENERATED ALWAYS AS', $clean, 'the CREATE TABLE must stay so MySQL recomputes the value');
    }

    public function test_a_dump_with_no_generated_columns_restores_verbatim(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'dump-test-');
        $dump = "INSERT INTO `users` (`id`) VALUES (1);\n";
        file_put_contents($path, $dump);

        try {
            $this->assertSame($path, DumpSanitizer::sanitizeFile($path));
            $this->assertSame($dump, file_get_contents($path));
        } finally {
            @unlink($path);
        }
    }

    public function test_sanitize_file_writes_a_scratch_copy_and_leaves_the_source_untouched(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'dump-test-');
        $dump = $this->dump();
        file_put_contents($path, $dump);
        $scratch = null;

        try {
            $scratch = DumpSanitizer::sanitizeFile($path);

            $this->assertNotSame($path, $scratch);
            $this->assertSame($dump, file_get_contents($path), 'the uploaded backup must never be modified');
            $this->assertStringNotContainsString('`comment`, `live_step`) VALUES', (string) file_get_contents($scratch));
        } finally {
            @unlink($path);

            if ($scratch !== null && $scratch !== $path) {
                @unlink($scratch);
            }
        }
    }
}
