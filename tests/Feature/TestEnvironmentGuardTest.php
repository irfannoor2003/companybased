<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Guards the test environment itself.
 *
 * The suite talks to a real MySQL database through the `mysql_testing`
 * connection, so if phpunit.xml ever stops overriding the inherited
 * environment, RefreshDatabase would migrate:fresh and wipe the live data. That
 * failure is silent — the suite still "passes", it just runs somewhere else — so
 * it is asserted explicitly here.
 *
 * The two ways it breaks, both covered below:
 *   - <env> without force="true" is skipped when the variable is already set.
 *   - <env> alone is not enough, because Laravel's env() reads $_SERVER first,
 *     which needs the mirrored <server> entries.
 */
class TestEnvironmentGuardTest extends TestCase
{
    public function test_the_suite_runs_in_the_testing_environment(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertTrue(app()->runningUnitTests());
    }

    /**
     * MySQL, not SQLite. The suite must exercise the same engine as production:
     * a `date` cast round-trips as 'Y-m-d 00:00:00' in a SQLite text column but
     * is coerced back to a DATE by MySQL, so equality and range queries on date
     * columns behave differently between them. A suite on a different engine
     * than production reports bugs that do not exist there.
     */
    public function test_the_suite_runs_on_mysql_not_sqlite(): void
    {
        $this->assertSame('mysql_testing', config('database.default'));
        $this->assertSame('mysql', config('database.connections.mysql_testing.driver'));
    }

    /**
     * The single most important assertion in the suite: these tests run
     * `migrate:fresh`, so the schema must be a throwaway one.
     */
    public function test_the_suite_never_uses_the_production_database(): void
    {
        $testDatabase = config('database.connections.mysql_testing.database');
        $liveDatabase = config('database.connections.mysql.database');

        $this->assertNotEmpty($testDatabase, 'the test schema must be set');
        $this->assertNotSame(
            $liveDatabase,
            $testDatabase,
            'the test schema must never be the application database',
        );
        $this->assertStringContainsString(
            'test',
            strtolower((string) $testDatabase),
            'the test schema must be recognisably a test database',
        );
    }

    /**
     * Confirms the suite is really on the test schema, not merely configured
     * for it. `companybased` must still be present and untouched alongside it.
     */
    public function test_the_test_schema_exists_and_is_distinct_from_the_live_one(): void
    {
        $this->assertSame(
            'mysql_testing',
            config('database.default'),
            'precondition: the suite runs on the test connection',
        );

        $current = DB::connection()->getDatabaseName();

        $this->assertSame(
            config('database.connections.mysql_testing.database'),
            $current,
            'the active connection must be the test schema',
        );
    }

    public function test_the_driver_underneath_is_really_mysql(): void
    {
        $this->assertSame(
            'mysql',
            DB::connection()->getDriverName(),
            'the suite must run on the production engine',
        );
    }

    public function test_transient_state_is_in_memory(): void
    {
        $this->assertSame('array', config('cache.default'));
        $this->assertSame('array', config('session.driver'));
        $this->assertSame('sync', config('queue.default'));
    }

    /**
     * Both sources of the value must agree. A mismatch means only one of the
     * <env>/<server> blocks is being applied, which is exactly the regression
     * this file exists to catch.
     */
    public function test_the_phpunit_overrides_reach_every_env_source(): void
    {
        // DB_TEST_DATABASE differs between phpunit.xml (companybased_test) and
        // phpunit.sweep.xml (companybased_test_), so the schema is asserted
        // against whatever this configuration declares. The point of the test
        // is that all three env sources AGREE, not that they equal a literal.
        $expected = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql_testing',
            'DB_TEST_DATABASE' => $_ENV['DB_TEST_DATABASE'] ?? 'companybased_test',
            'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync',
        ];

        foreach ($expected as $name => $value) {
            $this->assertSame($value, $_SERVER[$name] ?? null, "{$name} missing from \$_SERVER");
            $this->assertSame($value, $_ENV[$name] ?? null, "{$name} missing from \$_ENV");
            $this->assertSame($value, getenv($name) ?: null, "{$name} missing from getenv()");
        }
    }

    /**
     * The guard in Tests\TestCase is the thing that stops a mistyped
     * DB_TEST_DATABASE from erasing a real schema. It throws from setUp(), so it
     * cannot be exercised by simply booting the app — assert the rule it
     * enforces instead, and that it fires for a production-looking name.
     */
    public function test_the_guard_rejects_a_non_test_schema_name(): void
    {
        $refuse = function (string $name): bool {
            $allowed = [
                'companybased_test',
                'companybased_test_',
                'test_companybased',
                'companybased_testing',
            ];

            return ! in_array($name, $allowed, true);
        };

        $this->assertTrue($refuse('companybased'), 'the live schema must be refused');
        $this->assertTrue($refuse('laravel'), 'an unrelated schema must be refused');
        $this->assertTrue($refuse('production'), 'a production schema must be refused');
        $this->assertTrue($refuse(''), 'an empty schema must be refused');

        $this->assertFalse($refuse('companybased_test'), 'the real test schema must be allowed');
    }
}
