<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;

/**
 * A database dump/restore that never spawns a process.
 *
 * Many shared hosts disable proc_open() (and every other execution function) for
 * security. Symfony Process -- and therefore mysqldump/mysql -- simply cannot run
 * there, so a backup button that works in development fails on the deploying
 * server with "The Process class relies on proc_open". This class reads and writes
 * the same .sql format through PDO instead, so Backup & Restore keeps working on
 * those hosts without any binary or special privilege beyond SELECT.
 *
 * The output is deliberately plain, portable SQL: DROP/CREATE TABLE, batched
 * INSERTs, views, triggers, routines and events. The reader understands the
 * DELIMITER directive that mysqldump emits around compound statements, so dumps
 * downloaded from another host and uploaded here restore just as well as dumps
 * this class wrote itself.
 */
class MySqlDump
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = DB::connection()->getPdo();
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * Write a complete dump of the connected database to $target.
     */
    public function dump(string $target): void
    {
        $handle = fopen($target, 'w');

        if ($handle === false) {
            throw new \RuntimeException('could not open '.$target.' for writing.');
        }

        try {
            $this->write($handle, '-- Pure-PHP MySQL dump, generated '.now()->toIso8601String());
            $this->write($handle, 'SET FOREIGN_KEY_CHECKS=0;');
            $this->write($handle, "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';");
            $this->write($handle, 'SET NAMES utf8mb4;');
            $this->write($handle, '');

            foreach ($this->baseTables() as $table) {
                $this->dumpTable($handle, $table);
            }

            foreach ($this->objects('VIEWS', 'TABLE_NAME') as $view) {
                $create = $this->showCreate('SHOW CREATE VIEW '.$this->quoteName($view));

                if ($create !== null) {
                    $this->write($handle, 'DROP VIEW IF EXISTS '.$this->quoteName($view).';');
                    $this->write($handle, $create.';');
                    $this->write($handle, '');
                }
            }

            $this->dumpRoutines($handle);

            $this->write($handle, 'SET FOREIGN_KEY_CHECKS=1;');
        } finally {
            fclose($handle);
        }
    }

    /**
     * Replay a .sql file statement by statement.
     */
    public function restore(string $source): void
    {
        foreach ($this->statements($source) as $statement) {
            try {
                $this->pdo->exec($statement);
            } catch (\PDOException $e) {
                throw new \RuntimeException(
                    'Restore stopped at: '.Str::limit(preg_replace('/\s+/', ' ', $statement) ?: $statement, 140).' '.$e->getMessage(),
                    0,
                    $e,
                );
            }
        }
    }

    /**
     * Split a dump into individual statements without loading the whole file into
     * memory. Comments, quoted strings, escaped quotes and DELIMITER changes are
     * all handled, so a trigger body full of semicolons stays one statement.
     *
     * @return \Generator<int, string>
     */
    public function statements(string $source): \Generator
    {
        $handle = fopen($source, 'r');

        if ($handle === false) {
            throw new \RuntimeException('could not read the backup file.');
        }

        $buffer = '';
        $delimiter = ';';
        $quote = null;
        $comment = null;

        try {
            while (($line = fgets($handle)) !== false) {
                $length = strlen($line);

                for ($i = 0; $i < $length; $i++) {
                    $char = $line[$i];
                    $next = $line[$i + 1] ?? '';

                    if ($comment === 'line') {
                        if ($char === "\n") {
                            $comment = null;
                            $buffer .= $char;
                        }

                        continue;
                    }

                    if ($comment === 'block') {
                        if ($char === '*' && $next === '/') {
                            $comment = null;
                            $i++;
                        }

                        continue;
                    }

                    if ($quote !== null) {
                        $buffer .= $char;

                        // Backslash escapes: MySQL dumps use \' inside strings.
                        if ($char === '\\' && $quote !== '`' && $i + 1 < $length) {
                            $buffer .= $line[$i + 1];
                            $i++;

                            continue;
                        }

                        if ($char === $quote) {
                            // A doubled quote ('') is an escaped quote too.
                            if ($next === $quote) {
                                $buffer .= $next;
                                $i++;

                                continue;
                            }

                            $quote = null;
                        }

                        continue;
                    }

                    // DELIMITER is a client directive, not SQL: consume its line.
                    if ($i === 0 && preg_match('/^DELIMITER\s+(\S+)/i', $line, $matches) === 1) {
                        $delimiter = $matches[1];

                        continue 2;
                    }

                    if ($char === '-' && $next === '-' && in_array($line[$i + 2] ?? '', [' ', "\t"], true)) {
                        $comment = 'line';
                        $i += 2;

                        continue;
                    }

                    if ($char === '#') {
                        $comment = 'line';

                        continue;
                    }

                    if ($char === '/' && $next === '*') {
                        $comment = 'block';
                        $i++;

                        continue;
                    }

                    if ($char === "'" || $char === '"' || $char === '`') {
                        $quote = $char;
                        $buffer .= $char;

                        continue;
                    }

                    if (str_starts_with(substr($line, $i), $delimiter)) {
                        $statement = trim($buffer);

                        if ($statement !== '') {
                            yield $statement;
                        }

                        $buffer = '';
                        $i += strlen($delimiter) - 1;

                        continue;
                    }

                    $buffer .= $char;
                }
            }

            $statement = trim($buffer);

            if ($statement !== '') {
                yield $statement;
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return array<int, string>
     */
    private function baseTables(): array
    {
        return $this->pdo
            ->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME")
            ->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * @return array<int, string>
     */
    private function objects(string $schemaTable, string $column): array
    {
        return $this->pdo
            ->query("SELECT {$column} FROM information_schema.{$schemaTable} WHERE ".($schemaTable === 'VIEWS' ? 'TABLE_SCHEMA' : str_replace('S', '_SCHEMA', $schemaTable))." = DATABASE() ORDER BY {$column}")
            ->fetchAll(PDO::FETCH_COLUMN);
    }

    private function dumpTable($handle, string $table): void
    {
        $quoted = $this->quoteName($table);
        $create = $this->showCreate('SHOW CREATE TABLE '.$quoted);

        if ($create === null) {
            return;
        }

        $this->write($handle, 'DROP TABLE IF EXISTS '.$quoted.';');
        $this->write($handle, $create.';');

        // Unbuffered keeps memory flat on large tables: rows are streamed out of
        // MySQL and straight into the file rather than collected in an array.
        $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);

        try {
            $rows = $this->pdo->query('SELECT * FROM '.$quoted, PDO::FETCH_ASSOC);
            $columns = [];
            $batch = '';
            $inBatch = 0;

            foreach ($rows as $row) {
                if ($columns === []) {
                    $columns = array_map(fn (string $column): string => $this->quoteName($column), array_keys($row));
                }

                $batch .= ($inBatch === 0 ? 'INSERT INTO '.$quoted.' ('.implode(',', $columns).') VALUES' : ',')
                    .'('.implode(',', array_map(fn ($value): string => $this->quoteValue($value), $row)).')';

                $inBatch++;

                if ($inBatch >= 100) {
                    $this->write($handle, $batch.';');
                    $batch = '';
                    $inBatch = 0;
                }
            }

            $rows->closeCursor();

            if ($inBatch > 0) {
                $this->write($handle, $batch.';');
            }
        } finally {
            $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        }

        $this->write($handle, '');
    }

    private function dumpRoutines($handle): void
    {
        // SHOW CREATE puts the statement in a different column for each object
        // type: routines carry sql_mode (and events a time_zone) before it.
        $probes = [
            ['TRIGGERS', 'TRIGGER_NAME', 'SHOW CREATE TRIGGER', 1],
            ['ROUTINES', 'ROUTINE_NAME', null, 2],
            ['EVENTS', 'EVENT_NAME', 'SHOW CREATE EVENT', 3],
        ];

        foreach ($probes as [$schemaTable, $column, $show, $statementColumn]) {
            foreach ($this->objects($schemaTable, $column) as $name) {
                $kind = $show;

                if ($kind === null) {
                    $type = $this->pdo
                        ->query("SELECT ROUTINE_TYPE FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = ".$this->pdo->quote($name))
                        ->fetchColumn();
                    $kind = $type === 'FUNCTION' ? 'SHOW CREATE FUNCTION' : 'SHOW CREATE PROCEDURE';
                }

                $create = $this->showCreate($kind.' '.$this->quoteName($name), $statementColumn);

                if ($create === null) {
                    continue;
                }

                // Compound bodies contain semicolons, so the reader needs a
                // different statement terminator until the next DELIMITER line.
                $this->write($handle, 'DELIMITER $$');
                $this->write($handle, rtrim($create, "; \t\n\r\0\x0B").'$$');
                $this->write($handle, 'DELIMITER ;');
                $this->write($handle, '');
            }
        }
    }

    private function showCreate(string $sql, int $column = 1): ?string
    {
        $row = $this->pdo->query($sql)->fetch(PDO::FETCH_NUM);

        return $row === false ? null : (string) ($row[$column] ?? '');
    }

    private function quoteName(string $name): string
    {
        return '`'.str_replace('`', '``', $name).'`';
    }

    private function quoteValue(mixed $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $value ? '1' : '0',
            is_int($value), is_float($value) => (string) $value,
            default => $this->pdo->quote((string) $value),
        };
    }

    private function write($handle, string $line): void
    {
        fwrite($handle, $line."\n");
    }
}
