<?php

namespace Tests;

use App\Models\Subscription;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * The MySQL schemas the suite is allowed to run against.
     *
     * RefreshDatabase runs `migrate:fresh`, which DROPS every table in the
     * connected schema. Pointing the suite at the application's real schema
     * would therefore destroy production data — and it fails silently in the
     * worst way, because the suite still reports green while running somewhere
     * else. Anything not obviously a test schema is refused.
     */
    private const ALLOWED_TEST_DATABASES = [
        'companybased_test',
        'companybased_test_',
        'test_companybased',
        'companybased_testing',
    ];

    /**
     * Guards the connection before anything can migrate or truncate.
     *
     * This MUST run before parent::setUp(). The base TestCase calls
     * setUpTheTestEnvironment() -> setUpTraits() -> refreshDatabase(), which is
     * where `migrate:fresh` drops every table. A guard placed after
     * parent::setUp() would therefore throw only once the damage was done.
     *
     * The application is not booted yet at this point, so the values are read
     * straight from the environment — the same sources Laravel's env() would
     * use, in its own precedence order ($_SERVER, then $_ENV, then getenv()).
     */
    protected function setUp(): void
    {
        $this->guardAgainstProductionDatabase();

        parent::setUp();

        // Subscription::isAccessBlocked() memoises its verdict in a static, which
        // outlives an individual test because every test runs in the same PHP
        // process. Without this, whether a test sees "blocked" or "unblocked"
        // depends on which test happened to run before it. Reset after the parent
        // setUp so each test starts from the database's actual state.
        Subscription::flushCache();
    }

    /**
     * Read an environment value the way Laravel's env() helper would.
     */
    private function envValue(string $name): ?string
    {
        foreach ([$_SERVER[$name] ?? null, $_ENV[$name] ?? null, getenv($name) ?: null] as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Abort the run unless the connection is MySQL and the schema is
     * recognisably a test database.
     */
    private function guardAgainstProductionDatabase(): void
    {
        $connection = $this->envValue('DB_CONNECTION');

        if ($connection !== 'mysql_testing') {
            throw new RuntimeException(sprintf(
                'Refusing to run: the suite must use the "mysql_testing" connection, got "%s". '
                .'phpunit.xml is probably not applying its overrides, and the tests would otherwise '
                .'run against the application\'s real database.',
                (string) $connection,
            ));
        }

        $database = $this->envValue('DB_TEST_DATABASE');

        if ($database === null || ! in_array($database, self::ALLOWED_TEST_DATABASES, true)) {
            throw new RuntimeException(sprintf(
                'Refusing to run: DB_TEST_DATABASE is "%s", which is not a recognised test schema. '
                .'Tests run migrate:fresh and would erase it. Set DB_TEST_DATABASE to one of: %s.',
                (string) $database,
                implode(', ', self::ALLOWED_TEST_DATABASES),
            ));
        }

        // Belt and braces: the live schema is never permitted even if someone
        // adds it to the allow-list above by mistake.
        $live = $this->envValue('DB_DATABASE');

        if ($live !== null && $database === $live) {
            throw new RuntimeException(sprintf(
                'Refusing to run: the test schema "%s" is the application\'s own database. '
                .'Choose a separate schema such as "companybased_test".',
                $database,
            ));
        }
    }
}
