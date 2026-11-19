<?php

/**
 * Make a SQL dump restorable when it writes to GENERATED columns (MySQL 3105).
 *
 * Usage:
 *   php sanitize-dump.php live-backup.sql
 *   php sanitize-dump.php live-backup.sql restorable.sql
 *
 * With one argument the cleaned file is written beside the input as
 * <name>.restorable.sql. The input is never modified. The application's own
 * Settings -> Backup -> Restore flow applies the same cleaning automatically.
 */

require __DIR__.'/vendor/autoload.php';

use App\Support\DumpSanitizer;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this from the command line.\n");
    exit(1);
}

$input = $argv[1] ?? null;

if ($input === null || ! is_file($input)) {
    fwrite(STDERR, "Usage: php sanitize-dump.php <dump.sql> [output.sql]\n");
    exit(1);
}

$output = $argv[2] ?? preg_replace('/\.sql$/i', '', $input).'.restorable.sql';

$sql = file_get_contents($input);

if ($sql === false) {
    fwrite(STDERR, "Could not read {$input}\n");
    exit(1);
}

$generated = DumpSanitizer::generatedColumns($sql);

if ($generated === []) {
    copy($input, $output);
    fwrite(STDERR, "No GENERATED columns found; the dump was copied unchanged.\n");
    exit(0);
}

$clean = DumpSanitizer::strip($sql, $generated);

if (file_put_contents($output, $clean) === false) {
    fwrite(STDERR, "Could not write {$output}\n");
    exit(1);
}

$statements = preg_match_all('/INSERT\s+INTO\s+`/', $clean);
$names = [];

foreach ($generated as $columns) {
    $names = array_merge($names, $columns);
}

fwrite(STDOUT, sprintf(
    "Stripped generated column(s): %s\nTables: %d, INSERT statements: %d\nSaved: %s\n",
    implode(', ', $names),
    count($generated),
    $statements,
    $output,
));
