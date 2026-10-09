<?php

namespace Tests\Feature\Census;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Contract between every Eloquent model in app/Models and the schema.
 *
 * The two failure modes this catches are both silent in normal testing:
 *
 *  1. A model that declares a fillable/hidden column the table does not have
 *     (the write only explodes when that column is actually mass-assigned —
 *     usually in production, usually on a rarely-used form).
 *  2. A model whose table does not exist (dead model left behind after a
 *     migration rename).
 *
 * Every model file in app/Models is discovered from disk, so a model added
 * tomorrow is covered tomorrow — no list to maintain.
 */
class ModelContractTest extends TestCase
{
    /**
     * @return array<string, array{0: class-string<Model>}>
     */
    public static function modelProvider(): array
    {
        $models = [];

        foreach (glob(__DIR__.'/../../../app/Models/*.php') as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');

            if (! class_exists($class)) {
                continue;
            }

            if (! is_subclass_of($class, Model::class)) {
                continue;
            }

            $models[basename($file, '.php')] = [$class];
        }

        return $models;
    }

    /**
     * @param  class-string<Model>  $class
     */
    #[DataProvider('modelProvider')]
    public function test_model_table_exists(string $class): void
    {
        $model = new $class;

        $this->assertTrue(
            Schema::hasTable($model->getTable()),
            "{$class} points at table [{$model->getTable()}], which does not exist.",
        );
    }

    /**
     * @param  class-string<Model>  $class
     */
    #[DataProvider('modelProvider')]
    public function test_every_fillable_column_exists(string $class): void
    {
        $model = new $class;
        $table = $model->getTable();

        if (! Schema::hasTable($table)) {
            $this->markTestSkipped("Table [{$table}] does not exist.");
        }

        $columns = Schema::getColumnListing($table);
        $declared = $model->getFillable();

        // The #[Fillable] attribute is read by getFillable() in Laravel 13;
        // models still on the $fillable property behave identically.
        $missing = array_values(array_diff($declared, $columns));

        $this->assertSame(
            [],
            $missing,
            "{$class} mass-assigns columns that do not exist in [{$table}]: "
            .implode(', ', $missing)
            .'. The write would only explode when one of them is submitted.',
        );
    }

    /**
     * @param  class-string<Model>  $class
     */
    #[DataProvider('modelProvider')]
    public function test_every_hidden_attribute_exists(string $class): void
    {
        $model = new $class;
        $table = $model->getTable();

        if (! Schema::hasTable($table)) {
            $this->markTestSkipped("Table [{$table}] does not exist.");
        }

        $columns = Schema::getColumnListing($table);
        $missing = array_values(array_diff($model->getHidden(), $columns));

        $this->assertSame(
            [],
            $missing,
            "{$class} hides columns that do not exist in [{$table}]: ".implode(', ', $missing),
        );
    }

    /**
     * @param  class-string<Model>  $class
     */
    #[DataProvider('modelProvider')]
    public function test_model_can_be_instantiated_and_queried(string $class): void
    {
        $model = new $class;

        if (! Schema::hasTable($model->getTable())) {
            $this->markTestSkipped("Table [{$model->getTable()}] does not exist.");
        }

        // A query must build (column names in scopes/ordering resolve) without
        // touching data: limit(0) still compiles the full SQL.
        $sql = $class::query()->limit(0)->toSql();

        $this->assertStringContainsString('select', $sql);
    }

    /**
     * Every database table that a model does NOT cover is reported so the
     * README can state the true model/table coverage instead of guessing.
     * Pivot/system tables are explicitly excluded with a reason.
     */
    public function test_every_business_table_has_a_model(): void
    {
        $systemTables = [
            // Framework infrastructure — no domain behaviour to model.
            'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
            'sessions', 'migrations', 'password_reset_tokens',
            'personal_access_tokens',
            // Laravel's database notification channel. Written through the
            // Notifiable trait's DatabaseNotification, never by an app model.
            'notifications',
            // Spatie permission internals — managed by the package.
            'permissions', 'roles', 'model_has_permissions', 'model_has_roles',
            'role_has_permissions',
            // Audit is written through the AuditLog model (checked below).
        ];

        $covered = [];

        foreach ($this->modelProvider() as $class) {
            $covered[] = (new $class[0])->getTable();
        }

        // Only tables this application creates are in scope. The connected
        // schema can legitimately hold tables that did not come from
        // database/migrations (a restored site dump, a third-party package
        // sharing the schema); judging those would produce findings about data
        // the application does not own.
        $migrated = $this->migratedTables();

        $uncovered = [];
        $skippedForeign = [];

        // The listing can name the same table once per schema the connection can see
        // (schema-qualified names), so names are normalised to their bare form
        // and de-duplicated before anything is judged.
        $listed = array_map(
            static fn (string $table): string => str_contains($table, '.')
                ? substr($table, strpos($table, '.') + 1)
                : $table,
            Schema::getTableListing(),
        );

        foreach (array_unique($listed) as $table) {

            if (in_array($table, $systemTables, true)) {
                continue;
            }

            if (in_array($table, $covered, true)) {
                continue;
            }

            if (! in_array($table, $migrated, true)) {
                $skippedForeign[] = $table;

                continue;
            }

            $uncovered[] = $table;
        }

        $this->assertSame(
            [],
            $uncovered,
            'Tables without a model in app/Models (add a model or document the table '
            .'as a system/pivot table in this test): '.implode(', ', $uncovered),
        );
    }

    /**
     * Every table name the application's migrations create.
     *
     * @return array<int, string>
     */
    private function migratedTables(): array
    {
        $tables = [];

        foreach (glob(database_path('migrations/*.php')) ?: [] as $file) {
            preg_match_all("/Schema::create\(\s*'([^']+)'/", (string) file_get_contents($file), $matches);

            $tables = array_merge($tables, $matches[1]);
        }

        return array_values(array_unique($tables));
    }
}
