<?php

namespace Tests\Feature;

use App\Http\Controllers\Settings\BackupController;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * Settings > Backup & Restore shells out to mysqldump / mysql. These tests cover
 * the parts decided in PHP, which is where the destructive mistakes live: who
 * may reach the page, what a route parameter is allowed to name, what gets
 * pruned, and whether an unrecoverable restore is properly gated.
 *
 * The mysqldump round trip itself is covered by
 * test_a_backup_round_trips_through_mysqldump, which is skipped when no client
 * binary is present on the host.
 */
class DatabaseBackupTest extends TestCase
{
    // The trait owns setUp() because it must run seeding after the framework has
    // booted. Aliasing it lets this class add the storage fake without replacing
    // that method outright, which would silently skip the seed chain.
    use SeedsDatabase {
        setUp as private seedAndBoot;
    }

    protected function setUp(): void
    {
        $this->seedAndBoot();

        Storage::fake('backups');
    }

    private function superAdmin(): User
    {
        return User::where('email', 'superadmin@nexosdigital.test')->firstOrFail();
    }

    private function dump(string $name, int $ageInDays = 0): void
    {
        Storage::disk('backups')->put($name, '-- dump');

        if ($ageInDays > 0) {
            touch(Storage::disk('backups')->path($name), now()->subDays($ageInDays)->timestamp);
        }
    }

    /**
     * A user holding settings.backup.view and nothing else.
     *
     * Built by direct permission rather than by role: there is no "Viewer" role
     * in config/roles.php, and inventing one here would test a role that does
     * not ship.
     */
    private function viewOnlyUser(): User
    {
        $user = User::create([
            'name' => 'Read Only',
            'email' => 'readonly-'.uniqid().'@example.test',
            'password' => 'Password123!',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $user->givePermissionTo('settings.backup.view');

        return $user;
    }

    public function test_a_user_with_only_view_permission_can_see_the_list(): void
    {
        // The listing is a read-only page. A user granted settings.backup.view
        // but not .manage must reach it; a controller-level guard on `manage`
        // here would 403 a legitimate viewer.
        $this->dump('backup-2026-01-01-000000.sql');

        $this->actingAs($this->viewOnlyUser())
            ->get('/settings/backups')
            ->assertOk()
            ->assertSee('backup-2026-01-01-000000.sql');
    }

    public function test_a_viewer_cannot_create_download_or_delete(): void
    {
        $viewer = $this->viewOnlyUser();

        $this->dump('backup-2026-01-01-000000.sql');

        $this->actingAs($viewer)
            ->post('/settings/backups')
            ->assertForbidden();

        $this->actingAs($viewer)
            ->get('/settings/backups/backup-2026-01-01-000000.sql/download')
            ->assertForbidden();

        $this->actingAs($viewer)
            ->delete('/settings/backups/backup-2026-01-01-000000.sql')
            ->assertForbidden();

        $this->assertTrue(
            Storage::disk('backups')->exists('backup-2026-01-01-000000.sql'),
            'a refused delete must leave the file in place',
        );
    }

    public function test_a_user_with_no_backup_permission_is_refused(): void
    {
        $this->dump('backup-2026-01-01-000000.sql');

        $this->actingAs($this->userForRole('Employee'))
            ->get('/settings/backups')
            ->assertForbidden();
    }

    public function test_the_download_route_cannot_escape_the_backups_directory(): void
    {
        // {file} is user-controlled. A traversal segment must not resolve to a
        // file outside the backups root.
        $this->dump('backup-2026-01-01-000000.sql');

        $this->actingAs($this->superAdmin())
            ->get('/settings/backups/'.urlencode('../../../.env').'/download')
            ->assertNotFound();

        $this->actingAs($this->superAdmin())
            ->delete('/settings/backups/'.urlencode('../../.env'))
            ->assertNotFound();

        $this->assertFileExists(base_path('.env'), '.env must survive a traversal attempt');
    }

    public function test_a_non_sql_filename_is_refused(): void
    {
        Storage::disk('backups')->put('secrets.env', 'APP_KEY=leaked');

        $this->actingAs($this->superAdmin())
            ->get('/settings/backups/secrets.env/download')
            ->assertNotFound();

        $this->actingAs($this->superAdmin())
            ->delete('/settings/backups/secrets.env')
            ->assertNotFound();

        $this->assertTrue(Storage::disk('backups')->exists('secrets.env'));
    }

    public function test_a_missing_backup_is_a_404(): void
    {
        $this->actingAs($this->superAdmin())
            ->get('/settings/backups/nope.sql/download')
            ->assertNotFound();

        $this->actingAs($this->superAdmin())
            ->delete('/settings/backups/nope.sql')
            ->assertNotFound();
    }

    public function test_restore_requires_the_confirmation_phrase(): void
    {
        $this->actingAs($this->superAdmin())
            ->post('/settings/backups/restore', [
                'backup' => UploadedFile::fake()->createWithContent('backup.sql', 'SELECT 1;'),
            ])
            ->assertSessionHasErrors('confirmation');

        $this->assertSame(
            [],
            Storage::disk('backups')->allFiles(),
            'a rejected restore must not leave a scratch file behind',
        );
    }

    public function test_restore_rejects_a_wrong_confirmation_phrase(): void
    {
        $this->actingAs($this->superAdmin())
            ->post('/settings/backups/restore', [
                'backup' => UploadedFile::fake()->createWithContent('backup.sql', 'SELECT 1;'),
                'confirmation' => 'restore',
            ])
            ->assertSessionHasErrors('confirmation');

        $this->assertSame([], Storage::disk('backups')->allFiles());
    }

    public function test_restore_rejects_a_non_sql_upload(): void
    {
        $this->actingAs($this->superAdmin())
            ->post('/settings/backups/restore', [
                'backup' => UploadedFile::fake()->createWithContent('payload.php', '<?php echo 1;'),
                'confirmation' => 'RESTORE',
            ])
            ->assertSessionHasErrors('backup');

        $this->assertSame([], Storage::disk('backups')->allFiles());
    }

    public function test_the_mysql_connection_is_accepted(): void
    {
        // The guard must not be so broad that it refuses a working deployment.
        $connection = $this->controller()->connection();

        $this->assertIsArray($connection);
        $this->assertSame('mysql', $connection['driver']);
        $this->assertNotEmpty($connection['database']);
        $this->assertNotEmpty($connection['username']);
    }

    /**
     * The guard is exercised by handing it a connection array directly.
     *
     * It is deliberately NOT exercised by pointing `database.default` at another
     * connection. That rebuilds the live connection: Laravel opens a new PDO
     * handle and drops the old one while it still holds RefreshDatabase's open
     * transaction, so the orphaned handle keeps its metadata locks and the next
     * test's `migrate:fresh` blocks on them forever. MySQL's lock_wait_timeout
     * is one year, so the run shows no output and no CPU and looks hung. That
     * deadlock cost half an hour before it was diagnosed; nothing in a test
     * should be able to trigger it again.
     */
    public function test_a_non_mysql_driver_is_refused(): void
    {
        $this->assertNull(
            $this->controller()->connectionFor(['driver' => 'sqlite', 'database' => ':memory:']),
            'mysqldump cannot read a SQLite schema, so it must refuse before spawning a binary',
        );

        $this->assertNull(
            $this->controller()->connectionFor(['driver' => 'sqlsrv', 'database' => 'app']),
            'the guard is not MySQL-only-by-accident; any other driver is refused',
        );
    }

    public function test_an_incomplete_connection_is_refused(): void
    {
        // Handing mysqldump an empty schema argument produces a confusing
        // client-side error, so an unusable definition is rejected up front.
        $this->assertNull($this->controller()->connectionFor([
            'driver' => 'mysql', 'host' => '127.0.0.1', 'database' => '', 'username' => 'root',
        ]), 'no database name');

        $this->assertNull($this->controller()->connectionFor([
            'driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'app', 'username' => '',
        ]), 'no username');

        $this->assertNull($this->controller()->connectionFor([]), 'an empty definition');
    }

    public function test_a_failed_dump_writes_no_backup_and_leaves_no_scratch_file(): void
    {
        // No client binary resolves, so runDump() fails before it can write
        // anything. Pointed at a throwaway config key rather than at
        // database.connections.*: rewriting a live connection definition
        // rebuilds its PDO handle and leaks the metadata locks that deadlock
        // every later test's migrate:fresh.
        config()->set('backups.mysqldump', '/definitely/not/a/real/mysqldump');
        // `fallback` is the key that turns auto-detection off. Without it the
        // resolver ignores the bogus configured path, finds the real
        // mysqldump, and the dump succeeds — so this would assert nothing.
        config()->set('backups.fallback', false);

        $this->actingAs($this->superAdmin())
            ->post('/settings/backups')
            ->assertRedirect()
            ->assertSessionHas('toasts');

        $this->assertSame(
            [],
            Storage::disk('backups')->allFiles(),
            'a failed dump must never leave a file that looks like a usable backup',
        );
    }

    public function test_retention_keeps_the_newest_dumps_and_deletes_the_rest(): void
    {
        foreach (range(1, 6) as $index) {
            $this->dump("backup-2026-01-0{$index}-000000.sql", 10 - $index);
        }

        config()->set('backups.keep', 3);

        $this->assertSame(3, $this->controller()->prune(), 'three dumps should have been pruned');

        $remaining = $this->dumpNames();

        $this->assertSame(
            ['backup-2026-01-04-000000.sql', 'backup-2026-01-05-000000.sql', 'backup-2026-01-06-000000.sql'],
            $remaining,
            'the newest dumps are the ones kept',
        );
    }

    public function test_retention_never_deletes_everything(): void
    {
        // A misconfigured keep value of 0 must not wipe the backups directory.
        config()->set('backups.keep', 0);

        $this->dump('backup-2026-01-01-000000.sql', 5);
        $this->dump('backup-2026-01-02-000000.sql');

        $this->controller()->prune();

        $this->assertSame(
            ['backup-2026-01-02-000000.sql'],
            $this->dumpNames(),
            'retention must always leave at least the newest dump',
        );
    }

    public function test_retention_ignores_the_scratch_directory(): void
    {
        config()->set('backups.keep', 1);

        $this->dump('backup-2026-01-01-000000.sql', 5);
        $this->dump('backup-2026-01-02-000000.sql');
        Storage::disk('backups')->put('tmp/in-flight.sql', 'SELECT 1;');

        $this->controller()->prune();

        $this->assertSame(['backup-2026-01-02-000000.sql'], $this->dumpNames());
        $this->assertTrue(
            Storage::disk('backups')->exists('tmp/in-flight.sql'),
            'an in-flight scratch file must never be pruned',
        );
    }

    public function test_the_list_ignores_non_sql_and_scratch_files(): void
    {
        $this->dump('backup-2026-01-01-000000.sql');
        Storage::disk('backups')->put('notes.txt', 'not a dump');
        Storage::disk('backups')->put('tmp/in-flight.sql', 'SELECT 1;');

        $html = $this->actingAs($this->superAdmin())->get('/settings/backups')->assertOk()->getContent();

        $this->assertStringContainsString('backup-2026-01-01-000000.sql', $html);
        $this->assertStringNotContainsString('notes.txt', $html, 'a non-dump file must not be listed');
        $this->assertStringNotContainsString('in-flight.sql', $html, 'a scratch file must not be listed');
    }

    /**
     * Which flags the dump command may carry depends on which fork of the
     * MySQL client is installed on the host: MariaDB's binaries refuse
     * --set-gtid-purged and --ssl-mode outright, MySQL dropped --ssl in
     * return. So the flavor is probed off the resolved binary rather than
     * guessed from config or the server dialect — the client is what parses
     * the options, and a MySQL front-end pointed at a MariaDB server must
     * still be treated as MySQL.
     */
    public function test_the_client_flavor_is_probed_from_the_installed_binary(): void
    {
        $binary = $this->resolveMysqldump();

        if ($binary === null) {
            $this->markTestSkipped('No mysqldump binary is available on this host.');
        }

        $probe = new Process([$binary, '--version']);
        $probe->mustRun();

        $this->assertSame(
            str_contains($probe->getOutput(), 'MariaDB'),
            $this->controller()->isMariaFor($binary),
            'the flavor must follow the actual binary on this host',
        );
    }

    public function test_a_binary_that_cannot_be_probed_is_read_as_mysql(): void
    {
        // An unprobinable binary fails the dump on its own right after the
        // flags are decided; this default only has to pick the spelling the
        // command was originally written for, not invent a second failure.
        config()->set('backups.mysqldump', '/definitely/not/a/real/mysqldump');
        config()->set('backups.fallback', false);

        $this->assertFalse(
            $this->controller()->isMariaFor('/definitely/not/a/real/mysqldump'),
            'an unprobinable binary must not swap the MySQL flags out for MariaDB ones',
        );
    }

    public function test_a_backup_round_trips_through_mysqldump(): void
    {
        if ($this->resolveMysqldump() === null) {
            $this->markTestSkipped('No mysqldump binary is available on this host.');
        }

        $this->actingAs($this->superAdmin())
            ->post('/settings/backups')
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $files = $this->dumpNames();

        $this->assertCount(1, $files, 'exactly one dump should have been written');

        $contents = Storage::disk('backups')->get($files[0]);

        $this->assertStringContainsString('CREATE TABLE', $contents, 'the dump should contain schema DDL');
        $this->assertStringContainsString('users', $contents, 'the dump should contain table DML');
        $this->assertGreaterThan(1024, strlen($contents), 'the dump should not be a stub');

        $this->assertSame(
            [],
            Storage::disk('backups')->allFiles('tmp'),
            'no scratch file may survive a successful dump',
        );
    }

    /**
     * A controller with the protected helpers exposed, so retention and the
     * connection guard can be asserted directly instead of through a request
     * that would shell out to mysqldump.
     */
    private function controller(): BackupController
    {
        return new class extends BackupController
        {
            public function prune(): int
            {
                return $this->pruneOldBackups();
            }

            public function connectionFor(mixed $connection): ?array
            {
                return parent::connectionFor($connection);
            }

            public function connection(): ?array
            {
                return parent::connection();
            }

            public function isMariaFor(string $binary): bool
            {
                return $this->clientIsMaria($binary);
            }
        };
    }

    /**
     * @return array<int, string>
     */
    private function dumpNames(): array
    {
        return collect(Storage::disk('backups')->allFiles())
            ->reject(fn (string $file): bool => str_starts_with($file, 'tmp/'))
            ->filter(fn (string $file): bool => str_ends_with(strtolower($file), '.sql'))
            ->sort()
            ->values()
            ->all();
    }

    /**
     * The binary the controller would pick, or null when there is none. Mirrors
     * the production resolution so the skip condition is honest.
     */
    private function resolveMysqldump(): ?string
    {
        $candidates = array_filter([
            config('backups.mysqldump'),
            ...(str_starts_with(PHP_OS, 'WIN')
                ? (glob('C:\\laragon\\bin\\mysql\\*\\bin\\mysqldump.exe') ?: [])
                : ['/usr/bin/mysqldump', '/usr/local/bin/mysqldump']),
        ]);

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
