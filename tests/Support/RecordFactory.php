<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Schema-driven record factory.
 *
 * Creates a minimal, valid row for ANY table by reading the live schema:
 *
 *  - NOT-NULL columns without a default get a type-appropriate value
 *    (unique strings for unique-indexed columns so inserts never collide).
 *  - Foreign keys are satisfied by recursively creating the parent row first,
 *    with a cycle guard for self-referencing tables.
 *  - Enum columns get their first allowed value; JSON columns get '{}'.
 *
 * Rows are inserted with DB::table()->insertGetId() rather than Eloquent so
 * fillable/guarded rules, casts and model events never get in the way — this
 * factory exists purely to give route model binding a record to resolve.
 *
 * Created rows are cached per table for the lifetime of one test so a smoke
 * run over 600 routes pays the creation cost once per table.
 */
class RecordFactory
{
    /** @var array<string, int|string> table => primary key value */
    private array $cache = [];

    /** @var array<string, true> tables currently being created (cycle guard) */
    private array $creating = [];

    /** @var array{hasTable: callable, columns: callable, indexes: callable, foreignKeys: callable, columnType: callable}|null */
    private ?array $schemaCache = null;

    /**
     * Return the primary key of a record in $table, creating one if needed.
     *
     * @param  array<string, mixed>  $overrides  explicit column values (e.g. pointing a FK at an existing row)
     */
    public function keyFor(string $table, array $overrides = []): int|string
    {
        if ($overrides === [] && isset($this->cache[$table])) {
            return $this->cache[$table];
        }

        if (isset($this->creating[$table])) {
            // Cycle (e.g. users -> employees -> users). Break it so the caller
            // can decide whether a null/1 FK is acceptable for that column.
            throw new RuntimeException("Cycle while creating fixture for [{$table}].");
        }

        $this->creating[$table] = true;

        try {
            $values = $this->buildValues($table, $overrides);
            $id = DB::table($table)->insertGetId($values);
        } finally {
            unset($this->creating[$table]);
        }

        if ($overrides === []) {
            $this->cache[$table] = $id;
        }

        return $id;
    }

    /**
     * Always creates a brand-new row (no cache read, no cache write).
     *
     * Used by DELETE sweeps so deleting a fixture cannot break another
     * route that points at the cached row for the same table.
     */
    public function newKeyFor(string $table): int|string
    {
        // buildValues randomises every unique-indexed column (uniqid), so
        // repeat inserts for the same table never collide on a unique key.
        return DB::table($table)->insertGetId($this->buildValues($table, []));
    }

    /**
     * The value a route should carry to bind this model.
     *
     * Most models bind on `id`, but some override getRouteKeyName() — PosSale
     * binds on `receipt_number`. Resolving to the primary key for those
     * produces a 404 that looks exactly like a broken route.
     */
    public function routeKeyFor(string $table): int|string
    {
        return $this->routeKeyFromId($table, $this->keyFor($table));
    }

    /**
     * Same as routeKeyFor() but on a brand-new row, for destructive verbs.
     */
    public function routeKeyForNew(string $table): int|string
    {
        return $this->routeKeyFromId($table, $this->newKeyFor($table));
    }

    private function routeKeyFromId(string $table, int|string $id): int|string
    {
        $column = $this->routeKeyColumn($table);

        return $column === 'id'
            ? $id
            : (DB::table($table)->where('id', $id)->value($column) ?? $id);
    }

    /**
     * The column this table's model binds on, or 'id' when there is no model
     * or it uses the default.
     */
    public function routeKeyColumn(string $table): string
    {
        $modelClass = 'App\\Models\\'.$this->classNameForTable($table);

        if (! class_exists($modelClass)) {
            return 'id';
        }

        $model = new $modelClass;

        return $model->getRouteKeyName() ?: 'id';
    }

    private function classNameForTable(string $table): string
    {
        $candidates = [];

        foreach (glob(app_path('Models/*.php')) as $file) {
            $candidates[] = basename($file, '.php');
        }

        foreach ($candidates as $class) {
            $modelClass = 'App\\Models\\'.$class;

            if (class_exists($modelClass) && (new $modelClass)->getTable() === $table) {
                return $class;
            }
        }

        return 'Model';
    }

    /**
     * A model class's primary key, creating its table's record if needed.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function keyForModel(string $modelClass, array $overrides = []): int|string
    {
        /** @var \Illuminate\Database\Eloquent\Model $model */
        $model = new $modelClass;

        return $this->keyFor($model->getTable(), $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function buildValues(string $table, array $overrides): array
    {
        $schema = $this->schema();

        if (! $schema['hasTable']($table)) {
            throw new RuntimeException("Table [{$table}] does not exist.");
        }

        $values = [];
        $indexes = $schema['indexes']($table);
        $foreignKeys = $schema['foreignKeys']($table);
        $pk = null;

        foreach ($schema['columns']($table) as $column) {
            if (! empty($column['auto_increment'])) {
                $pk = $column['name'];
            }
        }

        foreach ($schema['columns']($table) as $column) {
            $name = $column['name'];

            if (array_key_exists($name, $overrides)) {
                $values[$name] = $overrides[$name];

                continue;
            }

            // Identity columns, generated columns and defaulted columns supply
            // themselves — only genuinely-required columns need a value.
            if (! empty($column['auto_increment']) || ! empty($column['generation'])) {
                continue;
            }

            if ($name === $pk) {
                continue;
            }

            if ($column['default'] !== null || $column['nullable']) {
                continue;
            }

            $values[$name] = $this->valueFor($table, $column, $indexes, $foreignKeys);
        }

        return $values;
    }

    /**
     * Type-appropriate value for one required column.
     *
     * @param  array<int, array<string, mixed>>  $indexes
     * @param  array<int, array<string, mixed>>  $foreignKeys
     */
    private function valueFor(string $table, array $column, array $indexes, array $foreignKeys): mixed
    {
        $name = $column['name'];
        $type = strtolower((string) ($column['type_name'] ?? 'string'));

        // FK first: satisfy the referenced table with a real parent row.
        foreach ($foreignKeys as $fk) {
            $fkColumns = $fk['columns'] ?? [];
            $position = array_search($name, $fkColumns, true);

            if ($position === false) {
                continue;
            }

            $parentTable = $fk['foreign_table'];
            $parentColumn = ($fk['foreign_columns'] ?? ['id'])[$position] ?? 'id';

            if ($parentTable === $table) {
                // Self-reference before insert: use the first existing row (the
                // seeder always creates users), else id 1 after first insert.
                return 1;
            }

            try {
                $parentKey = $this->keyFor($parentTable);
            } catch (RuntimeException) {
                if (DB::table($parentTable)->where($parentColumn, 1)->exists()) {
                    return 1;
                }

                throw new RuntimeException("Cannot satisfy FK {$table}.{$name} -> {$parentTable}.{$parentColumn}");
            }

            return $parentColumn === 'id' ? $parentKey : $this->lookupKey($parentTable, $parentColumn, $parentKey);
        }

        return match (true) {
            // Spatie's guard_name has no DB default and must name a real guard;
            // anything else makes Role/Permission resolution blow up.
            $name === 'guard_name' => 'web',

            // A NOT NULL date/datetime column that participates in a unique
            // index (attendance_records.employee_id+attendance_date,
            // holidays.holiday_date) must vary per row or the second fixture
            // collides with the first.
            in_array($type, ['date'], true) && $this->isInUniqueIndex($table, $name, $indexes)
                => now()->addDays(random_int(-400, 400))->toDateString(),
            in_array($type, ['datetime', 'timestamp'], true) && $this->isInUniqueIndex($table, $name, $indexes)
                => now()->addDays(random_int(-400, 400))->toDateTimeString(),

            $type === 'enum' || $type === 'set' => $this->firstEnumValue($table, $name),
            in_array($type, ['json', 'jsonb'], true) => '{}',
            in_array($type, ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint'], true) => $this->uniqueInt($table, $name, $indexes),
            in_array($type, ['decimal', 'numeric', 'float', 'double', 'real'], true) => '0.00',
            $type === 'date' => now()->toDateString(),
            in_array($type, ['datetime', 'timestamp', 'time'], true) => now()->toDateTimeString(),
            $type === 'year' => (string) now()->year,
            in_array($type, ['blob', 'binary', 'varbinary'], true) => '',
            default => $this->uniqueString($table, $name, $indexes),
        };
    }

    /**
     * The declared width of a character column, or null when the column has no
     * practical limit (TEXT and friends).
     */
    private function maxLength(string $table, string $column): ?int
    {
        $definition = (string) $this->schema()['columnType']($table, $column, true);

        if (preg_match('/^(?:var)?char\((\d+)\)/i', $definition, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Strings that land in a UNIQUE index must be unique per row, otherwise the
     * second fixture row for the table blows up the whole census run. The value
     * is also clipped to the column's declared width: several business tables
     * use short codes (accounts.code varchar(20), budgets.fiscal_year).
     *
     * @param  array<int, array<string, mixed>>  $indexes
     */
    private function uniqueString(string $table, string $column, array $indexes): string
    {
        $max = $this->maxLength($table, $column) ?? 60;
        $max = min($max, 60);

        $value = $this->isInUniqueIndex($table, $column, $indexes)
            ? 'fx_'.uniqid('', true)
            : 'Fixture';

        return substr($value, 0, $max);
    }

    /**
     * Whether the column takes part in ANY unique index — single column or
     * composite. Composite matters: roles has unique(name, guard_name), so a
     * second 'Fixture' role collides even though `name` alone is not unique.
     *
     * @param  array<int, array<string, mixed>>  $indexes
     */
    private function isInUniqueIndex(string $table, string $column, array $indexes): bool
    {
        foreach ($indexes as $index) {
            if (empty($index['unique'])) {
                continue;
            }

            if (in_array($column, $index['columns'] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $indexes
     */
    private function uniqueInt(string $table, string $column, array $indexes): int
    {
        if ($this->isUnique($table, $column, $indexes)) {
            return random_int(100000, 999999999);
        }

        return 0;
    }

    /**
     * @param  array<int, array<string, mixed>>  $indexes
     */
    private function isUnique(string $table, string $column, array $indexes): bool
    {
        foreach ($indexes as $index) {
            if (empty($index['unique'])) {
                continue;
            }

            if (($index['columns'] ?? []) === [$column]) {
                return true;
            }
        }

        return false;
    }

    private function firstEnumValue(string $table, string $column): string
    {
        $definition = $this->schema()['columnType']($table, $column, true);

        if (preg_match("/^enum\\((.*)\\)$/i", (string) $definition, $m)) {
            preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $m[1], $matches);

            if ($matches[1] !== []) {
                return str_replace("\\'", "'", $matches[1][0]);
            }
        }

        return '';
    }

    private function lookupKey(string $table, string $column, int|string $id): int|string
    {
        return DB::table($table)->where('id', $id)->value($column) ?? $id;
    }

    /**
     * Cached schema introspection callbacks, scoped to THIS test.
     *
     * Deliberately an instance property, not a static: PHPUnit boots a fresh
     * application per test and destroys the previous one, so a cached
     * container-bound builder would keep pointing at the dead container and
     * every fixture created after the first test in a process would fail with
     * "Target class [config] does not exist".
     *
     * @return array{hasTable: callable, columns: callable, indexes: callable, foreignKeys: callable, columnType: callable}
     */
    private function schema(): array
    {
        if ($this->schemaCache !== null) {
            return $this->schemaCache;
        }

        $builder = app(Builder::class);

        return $this->schemaCache = [
            'hasTable' => fn (string $table): bool => $builder->hasTable($table),
            'columns' => fn (string $table): array => $builder->getColumns($table),
            'indexes' => fn (string $table): array => $builder->getIndexes($table),
            'foreignKeys' => fn (string $table): array => $builder->getForeignKeys($table),
            'columnType' => fn (string $table, string $column, bool $full): string => (string) $builder->getColumnType($table, $column, $full),
        ];
    }
}

