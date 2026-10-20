<?php

/**
 * Deployment rehearsal.
 *
 * Dumps the working database, restores it into a scratch schema, and runs the
 * upgrade path against that copy rather than against real data.
 *
 * WHY
 *
 * "Migrate is safe" is a claim. The tax-engine rehearsal of 2026-09 showed why it is
 * a claim worth testing: rolling back a migration that looks innocent failed, and
 * only because someone tried it on a copy. This pass added a seeded table, changed a
 * renderer every document type prints through, and deleted a Blade template three
 * files used to name -- and none of that is something to find out about on a
 * customer's database.
 *
 * WHAT IT CHECKS, IN ORDER
 *
 *   1. The dump and restore preserve row counts, exactly.
 *   2. `migrate --force` on the restored copy leaves row counts alone.
 *   3. `db:seed --force` is safe to re-run: it does not duplicate the templates it
 *      owns, and does not move a default off the row an administrator already chose.
 *   4. Documents still render from the restored copy, in all five styles.
 *   5. Nothing in the restored copy refers to the deleted invoice template.
 *
 * USAGE
 *
 *   php rehearse_deploy.php [schema-name]
 *
 * Writes the scratch schema, the dump and the rendered PDFs under
 * `storage/app/rehearsal/`. Leaves the scratch schema in place afterwards so the
 * failure can be looked at; drop it with `php rehearse_deploy.php --drop`.
 *
 * Development tool. Not production code, and it does not touch the working
 * database except to read it.
 */

use App\Models\DocumentTemplate;
use App\Models\SalesQuote;
use App\Support\DocumentData;
use App\Support\PdfLayout;
use Barryvdh\DomPDF\Facade\Pdf;
use Database\Seeders\DocumentTemplatesSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$root = __DIR__;

require $root.'/vendor/autoload.php';

$drop = in_array('--drop', $argv, true);

/**
 * The schema name is the first argument that is not a flag.
 *
 * `$argv[1]` alone would take `--drop` as the schema name on `php rehearse_deploy.php
 * --drop`, and cheerfully report `Dropped --drop.` -- a syntax error at the SQL
 * layer that reads exactly like a correct message.
 */
$positional = array_values(array_filter(
    array_slice($argv, 1),
    fn (string $argument): bool => ! str_starts_with($argument, '--'),
));

$schema = $positional[0] ?? 'companybased_rehearsal';

/** @var Application $app */
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// After the bootstrap, not before: storage_path() needs the application container.
$out = storage_path('app/rehearsal');

if (! is_dir($out)) {
    mkdir($out, 0755, true);
}

$mysql = 'C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe';
$dump = 'C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysqldump.exe';

$failures = [];
$checks = 0;

function check(string $label, bool $passed, string $detail = ''): void
{
    global $failures, $checks;

    $checks++;

    printf("  %-4s %s%s\n", $passed ? 'PASS' : 'FAIL', $label, $detail === '' ? '' : '  — '.$detail);

    if (! $passed) {
        $failures[] = $label.($detail === '' ? '' : ': '.$detail);
    }
}

function rowCounts(string $schema): array
{
    DB::purge('mysql');

    return DB::connection('mysql')
        ->select(
            'SELECT table_name AS t, table_rows AS n FROM information_schema.tables '
            .'WHERE table_schema = ? AND table_type = "BASE TABLE"',
            [$schema],
        )
        ->mapWithKeys(fn ($row) => [$row->t => (int) $row->n])
        ->all();
}

function exactCounts(string $schema): array
{
    DB::purge('mysql');

    config(['database.connections.mysql.database' => $schema]);
    DB::purge('mysql');
    DB::connection('mysql')->getPdo();

    $out = [];

    foreach (DB::connection('mysql')->select('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"') as $row) {
        $table = array_values((array) $row)[0];

        // Exact, not the information_schema estimate, which is routinely wrong by
        // tens of percent on InnoDB and would hide a real data loss.
        $out[$table] = (int) DB::connection('mysql')->table($table)->count();
    }

    return $out;
}

/**
 * Pipe $stdinFile into a command's standard input.
 *
 * `cmd /c "mysql ... < file"` looks simpler and is not: the quoting rules differ
 * between shells, the exit code that comes back is not reliably the client's, and a
 * malformed redirect fails with a message on a stream nobody is reading. This pass
 * produced an EMPTY scratch schema and reported the restore as successful, then
 * reported the missing rows as a migration defect.
 *
 * proc_open feeds the file in directly and returns the client's own exit code.
 *
 * @return array{0: int, 1: string} exit code and whatever the client said
 */
function pipeInto(array $command, string $stdinFile): array
{
    $descriptors = [0 => ['file', $stdinFile, 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

    $process = proc_open($command, $descriptors, $pipes);

    if (! is_resource($process)) {
        return [1, 'could not start: '.implode(' ', $command)];
    }

    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), trim($output)];
}

if ($drop) {
    DB::statement("DROP DATABASE IF EXISTS `{$schema}`");

    echo "Dropped {$schema}.".PHP_EOL;

    return;
}

echo 'Rehearsal against: '.$schema.PHP_EOL.PHP_EOL;

// ---------------------------------------------------------------------------
echo '1. Dump the working database'.PHP_EOL;

$dumpFile = $out.'/working.sql';

$command = escapeshellcmd($dump)
    .' -u root --single-transaction --routines --events --result-file='
    .escapeshellarg($dumpFile).' companybased 2>&1';

exec($command, $dumpOutput, $dumpStatus);

check('mysqldump succeeded', $dumpStatus === 0, implode(' ', array_slice($dumpOutput, 0, 3)));
check('the dump is not empty', is_file($dumpFile) && filesize($dumpFile) > 10000, (string) (filesize($dumpFile) ?? 0).' bytes');

// ---------------------------------------------------------------------------
echo PHP_EOL.'2. Restore into a scratch schema'.PHP_EOL;

DB::statement("DROP DATABASE IF EXISTS `{$schema}`");
DB::statement("CREATE DATABASE `{$schema}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

[$restoreStatus, $restoreOutput] = pipeInto([$mysql, '-u', 'root', $schema], $dumpFile);

/**
 * Abort here rather than reporting a cascade.
 *
 * A failed restore leaves an empty schema, and every check after this one then
 * "fails" for a reason that has nothing to do with the code under test -- which is
 * how a working migration gets reported as broken. The first failed step stops the
 * rehearsal.
 */
check('the restore succeeded', $restoreStatus === 0, $restoreOutput === '' ? '' : substr($restoreOutput, 0, 300));

if ($restoreStatus !== 0) {
    echo PHP_EOL.'Restore failed; stopping. Everything after this point would be noise.'.PHP_EOL;

    exit(1);
}

$before = exactCounts('companybased');
$after = exactCounts($schema);

check(
    'the restore actually produced tables',
    $after !== [],
    count($after).' tables',
);

if ($after === []) {
    echo PHP_EOL.'The scratch schema is empty; stopping.'.PHP_EOL;

    exit(1);
}

$tables = count($before);
$rows = array_sum($before);

check(
    'every table came across',
    count($after) === count($before),
    count($after).' of '.$tables.' tables',
);

$mismatched = [];

foreach ($before as $table => $count) {
    if (($after[$table] ?? null) !== $count) {
        $mismatched[] = "{$table} {$count} -> ".($after[$table] ?? 'missing');
    }
}

check(
    'every row count is identical',
    $mismatched === [],
    $mismatched === [] ? $rows.' rows across '.$tables.' tables' : implode('; ', array_slice($mismatched, 0, 5)),
);

// ---------------------------------------------------------------------------
echo PHP_EOL.'3. Migrate the restored copy forward'.PHP_EOL;

config(['database.connections.mysql.database' => $schema]);
DB::purge('mysql');
DB::connection('mysql')->getPdo();

Artisan::call('migrate', ['--force' => true]);
$migrateOutput = Artisan::output();

$pending = trim(Artisan::output());

check(
    'migrate --force ran',
    ! str_contains(strtolower($migrateOutput), 'exception')
        && ! str_contains(strtolower($migrateOutput), 'sqlstate'),
    trim(substr($migrateOutput, -160)),
);

$afterMigrate = exactCounts($schema);
$drift = [];

foreach ($before as $table => $count) {
    if (($afterMigrate[$table] ?? null) !== $count) {
        $drift[] = "{$table} {$count} -> ".($afterMigrate[$table] ?? 'missing');
    }
}

check('migrating changed no row counts', $drift === [], implode('; ', array_slice($drift, 0, 5)));

// ---------------------------------------------------------------------------
echo PHP_EOL.'4. Re-seed, twice, and check it is safe to repeat'.PHP_EOL;

$chosen = DocumentTemplate::where('type', 'quote')->where('is_default', true)->first();
$chosenId = $chosen?->id;
$chosenStyle = $chosen?->style;

$countBefore = DocumentTemplate::count();
$defaultsBefore = DocumentTemplate::where('is_default', true)->count();

Artisan::call('db:seed', ['--force' => true, '--class' => DocumentTemplatesSeeder::class]);
Artisan::call('db:seed', ['--force' => true, '--class' => DocumentTemplatesSeeder::class]);

check(
    'reseeding does not duplicate templates',
    DocumentTemplate::count() === $countBefore,
    $countBefore.' -> '.DocumentTemplate::count(),
);

check(
    'reseeding does not change the number of defaults',
    DocumentTemplate::where('is_default', true)->count() === $defaultsBefore,
);

check(
    'reseeding leaves the chosen default alone',
    DocumentTemplate::find($chosenId)?->is_default === true
        && DocumentTemplate::find($chosenId)?->style === $chosenStyle,
    'style '.$chosenStyle.' -> '.DocumentTemplate::find($chosenId)?->style,
);

$defaultsPerType = DocumentTemplate::get()
    ->groupBy('type')
    ->map(fn ($group) => $group->where('is_default', true)->count())
    ->filter(fn ($n) => $n !== 1);

check(
    'every type has exactly one default',
    $defaultsPerType->isEmpty(),
    $defaultsPerType->isEmpty() ? '8 types' : $defaultsPerType->toJson(),
);

check(
    'every template resolves to a real style',
    DocumentTemplate::get()->every(fn ($t) => array_key_exists((string) $t->style, PdfLayout::STYLES)),
);

// ---------------------------------------------------------------------------
echo PHP_EOL.'5. Render documents from the restored copy'.PHP_EOL;

$quote = SalesQuote::with('items')->whereHas('items')->orderByDesc('id')->first();

if ($quote === null) {
    check('there is a document to render', false, 'the working database has no quote with lines');
} else {
    $rendered = [];

    foreach (array_keys(PdfLayout::STYLES) as $style) {
        try {
            $target = DocumentTemplate::ofType('quote')->where('style', $style)->first();

            if ($target === null) {
                // No preset for this design, so render the default one restyled --
                // otherwise the rehearsal would skip exactly the styles a customer
                // can pick but the seeder does not ship.
                $target = DocumentTemplate::ofType('quote')->where('is_default', true)->firstOrFail();

                $layout = (array) $target->layout;
                $layout['style'] = $style;
                $target->update(['style' => $style, 'layout' => $layout]);
            }

            /**
             * Demote the others, then promote this one.
             *
             * The other order clears every default first and then looks for one,
             * which throws on the second style through -- the rehearsal found that
             * by crashing rather than by reporting it.
             */
            DocumentTemplate::ofType('quote')
                ->where('id', '!=', $target->id)
                ->update(['is_default' => false]);

            $target->update(['is_default' => true]);

            $html = view('documents.pdf', DocumentData::build($quote))->render();

            $pdf = Pdf::loadHTML($html)
                ->setPaper('a4', 'portrait')
                ->setOptions(pdf_options())
                ->output();

            file_put_contents($out.'/rehearsal-'.$style.'.pdf', $pdf);

            $rendered[$style] = $pdf;

            check(
                "the {$style} design renders",
                str_starts_with($pdf, '%PDF') && strlen($pdf) > 5000,
                round(strlen($pdf) / 1024, 1).' KB',
            );
        } catch (Throwable $e) {
            check("the {$style} design renders", false, $e->getMessage());
        }
    }

    check(
        'the five designs render five different documents',
        count(array_unique($rendered)) === count(PdfLayout::STYLES),
        count(array_unique($rendered)).' distinct of '.count(PdfLayout::STYLES),
    );
}

// ---------------------------------------------------------------------------
echo PHP_EOL.'6. Nothing refers to the deleted invoice template'.PHP_EOL;

$offenders = [];

foreach ([app_path(), resource_path('views')] as $root_) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root_));

    foreach ($iterator as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.php') && str_contains((string) file_get_contents($file->getPathname()), 'pdf-rail')) {
            $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
        }
    }
}

check('no application file names the retired template', $offenders === [], implode(', ', $offenders));

check(
    'the retired template file is gone',
    ! file_exists(resource_path('views/sales/invoices/partials/pdf-rail.blade.php')),
);

// ---------------------------------------------------------------------------
echo PHP_EOL.str_repeat('-', 60).PHP_EOL;

printf(
    "%s — %d checks, %d failed\n",
    $failures === [] ? 'REHEARSAL PASSED' : 'REHEARSAL FAILED',
    $checks,
    count($failures),
);

foreach ($failures as $failure) {
    echo '  - '.$failure.PHP_EOL;
}

echo PHP_EOL.'Scratch schema "'.$schema.'" left in place. Drop it with:'.PHP_EOL;
echo '  php rehearse_deploy.php --drop '.$schema.PHP_EOL;
echo 'Rendered PDFs: '.$out.PHP_EOL;

exit($failures === [] ? 0 : 1);
