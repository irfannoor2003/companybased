<?php

namespace App\Support;

/**
 * Makes a SQL dump restorable when it writes to GENERATED columns.
 *
 * MySQL refuses an INSERT that supplies a value for a generated column:
 *
 *   ERROR 3105 (HY000): The value specified for generated column 'live_step'
 *   in table 'approval_requests' is not allowed.
 *
 * Some dump tools -- MariaDB's mysqldump, HeidiSQL, phpMyAdmin "complete
 * inserts", a number of backup packages -- include generated columns in INSERT
 * statements. MySQL's own mysqldump does not. The dump still carries the right
 * values; they simply must not be written by the importer, because MySQL
 * recomputes them from the expression in CREATE TABLE.
 *
 * This class reads the generated columns from the dump's OWN create statements
 * and removes only those columns from that table's INSERT lists and tuples. No
 * data is changed and no other column is touched.
 */
class DumpSanitizer
{
    /**
     * Strip generated columns from a dump, writing the result to a scratch file.
     *
     * Returns the path to import. When the dump has nothing to strip, the
     * original path is returned and no scratch file is created. The caller owns
     * the returned file and should delete it when it differs from $source.
     */
    public static function sanitizeFile(string $source): string
    {
        $sql = file_get_contents($source);

        if ($sql === false) {
            return $source;
        }

        $generated = self::generatedColumns($sql);

        if ($generated === []) {
            return $source;
        }

        $clean = self::strip($sql, $generated);

        $target = tempnam(sys_get_temp_dir(), 'dump-clean-');

        if ($target === false) {
            return $source;
        }

        if (file_put_contents($target, $clean) === false) {
            @unlink($target);

            return $source;
        }

        return $target;
    }

    /**
     * @param  array<string,array<int,string>>  $generated
     */
    public static function strip(string $sql, array $generated): string
    {
        if ($generated === []) {
            return $sql;
        }

        $columnsByTable = self::tableColumns($sql);

        // Form 1: INSERT INTO t (`a`, `b`) VALUES (...). Drop the named column.
        $sql = (string) preg_replace_callback(
            '/INSERT\s+INTO\s+`?([A-Za-z0-9_]+)`?\s*\(([^)]*)\)\s*VALUES\s*(.*?);/is',
            static function (array $m) use ($generated): string {
                $table = $m[1];

                if (! isset($generated[$table])) {
                    return $m[0];
                }

                $columns = self::splitList($m[2]);
                $drop = [];

                foreach ($generated[$table] as $column) {
                    foreach ($columns as $index => $name) {
                        if (strcasecmp(trim($name, '` '), $column) === 0) {
                            $drop[] = $index;
                        }
                    }
                }

                if ($drop === []) {
                    return $m[0];
                }

                $kept = [];

                foreach ($columns as $index => $name) {
                    if (! in_array($index, $drop, true)) {
                        $kept[] = $name;
                    }
                }

                return 'INSERT INTO `'.$table.'` ('.implode(',', $kept).') VALUES '
                    .self::pruneValues($m[3], $drop).';';
            },
            $sql,
        );

        /*
         * Form 2: INSERT INTO t VALUES (...) -- no column list.
         *
         * mysqldump emits this form by default. The generated column is still
         * the last value in every tuple, but there is no name to remove, so the
         * position has to come from the CREATE TABLE column order. We add an
         * explicit list of the non-generated columns so the remaining values
         * still line up after the generated one is pruned.
         */
        return (string) preg_replace_callback(
            '/INSERT\s+INTO\s+`?([A-Za-z0-9_]+)`?\s+VALUES\s*(.*?);/is',
            static function (array $m) use ($generated, $columnsByTable): string {
                $table = $m[1];

                if (! isset($generated[$table], $columnsByTable[$table])) {
                    return $m[0];
                }

                $columns = $columnsByTable[$table];
                $drop = [];

                foreach ($generated[$table] as $column) {
                    $index = array_search($column, $columns, true);

                    if ($index !== false) {
                        $drop[] = $index;
                    }
                }

                if ($drop === []) {
                    return $m[0];
                }

                $kept = [];

                foreach ($columns as $index => $name) {
                    if (! in_array($index, $drop, true)) {
                        $kept[] = '`'.$name.'`';
                    }
                }

                return 'INSERT INTO `'.$table.'` ('.implode(',', $kept).') VALUES '
                    .self::pruneValues($m[2], $drop).';';
            },
            $sql,
        );
    }

    /**
     * The column order of each table in the dump, taken from CREATE TABLE.
     * Positional INSERTs need it to know which value belongs to a generated
     * column. Column definitions in a mysqldump are one per line.
     *
     * @return array<string,array<int,string>>
     */
    public static function tableColumns(string $sql): array
    {
        $found = [];

        preg_match_all(
            '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?\s*\((.*?)\)\s*ENGINE=/is',
            $sql,
            $tables,
            PREG_SET_ORDER,
        );

        foreach ($tables as $table) {
            $columns = [];

            foreach (preg_split('/\R/', $table[2]) ?: [] as $line) {
                if (preg_match('/^\s*`([A-Za-z0-9_]+)`\s+/', $line, $m)) {
                    $columns[] = $m[1];
                }
            }

            $found[$table[1]] = $columns;
        }

        return $found;
    }

    /**
     * Find (table => generated columns) from the dump's create statements.
     *
     * @return array<string,array<int,string>>
     */
    public static function generatedColumns(string $sql): array
    {
        $found = [];

        preg_match_all(
            '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?\s*\((.*?)\)\s*ENGINE=/is',
            $sql,
            $tables,
            PREG_SET_ORDER,
        );

        foreach ($tables as $table) {
            preg_match_all('/`([A-Za-z0-9_]+)`[^,]*?\bGENERATED\s+ALWAYS\s+AS\s*\(/is', $table[2], $cols);

            if ($cols[1] !== []) {
                $found[$table[1]] = array_values(array_unique($cols[1]));
            }
        }

        return $found;
    }

    /**
     * Split on commas that are not inside quotes or parentheses.
     *
     * @return array<int,string>
     */
    private static function splitList(string $text): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $quote = null;
        $length = strlen($text);

        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];

            if ($quote !== null) {
                $current .= $char;

                if ($char === '\\' && $i + 1 < $length) {
                    $current .= $text[++$i];
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $current .= $char;

                continue;
            }

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            }

            if ($char === ',' && $depth === 0) {
                $parts[] = trim($current);
                $current = '';

                continue;
            }

            $current .= $char;
        }

        if (trim($current) !== '') {
            $parts[] = trim($current);
        }

        return $parts;
    }

    /**
     * Remove values at $indexes from each tuple in a VALUES list, leaving
     * commas inside quoted strings and nested expressions alone.
     *
     * @param  array<int,int>  $indexes
     */
    private static function pruneValues(string $values, array $indexes): string
    {
        sort($indexes);

        $out = '';
        $i = 0;
        $length = strlen($values);

        while ($i < $length) {
            if ($values[$i] !== '(') {
                $out .= $values[$i++];

                continue;
            }

            $tuple = '(';
            $depth = 1;
            $quote = null;
            $i++;

            while ($i < $length && $depth > 0) {
                $char = $values[$i];
                $tuple .= $char;

                if ($quote !== null) {
                    if ($char === '\\' && $i + 1 < $length) {
                        $tuple .= $values[++$i];
                    } elseif ($char === $quote) {
                        $quote = null;
                    }

                    $i++;

                    continue;
                }

                if ($char === "'" || $char === '"' || $char === '`') {
                    $quote = $char;
                } elseif ($char === '(') {
                    $depth++;
                } elseif ($char === ')') {
                    $depth--;
                }

                $i++;
            }

            $fields = self::splitList(substr($tuple, 1, -1));

            foreach ($indexes as $offset) {
                unset($fields[$offset]);
            }

            $out .= '('.implode(',', array_values($fields)).')';
        }

        return $out;
    }
}
