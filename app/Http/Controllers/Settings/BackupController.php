<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Process\Process;

class BackupController extends Controller
{
    public function index(): View
    {
        $this->authorizePermission('settings.backup.view');

        $disk = Storage::disk('backups');

        $backups = $this->backupFiles($disk)
            ->map(fn (string $file): array => [
                'path' => $file,
                'name' => basename($file),
                'size' => $disk->size($file),
                'modified' => $disk->lastModified($file),
            ])
            ->sortByDesc('modified')
            ->values();

        return view('settings.backups', [
            'backups' => $backups,
            'retention' => $this->retention(),
        ]);
    }

    public function create(): RedirectResponse
    {
        $this->authorizePermission('settings.backup.manage');

        if (! $connection = $this->connection()) {
            return back()->with('toasts', [['type' => 'error', 'message' => $this->unsupportedDriverMessage()]]);
        }

        $filename = 'backup-'.now()->format('Y-m-d-His').'-'.Str::random(6).'.sql';
        $disk = Storage::disk('backups');

        // Write to a scratch name first so a failure or a crash mid-dump can
        // never leave a truncated file that looks like a usable backup.
        $scratch = 'tmp/'.Str::uuid()->toString().'.sql';
        $disk->put($scratch, '');

        try {
            $this->runDump($connection, $disk->path($scratch));
        } catch (\RuntimeException $e) {
            $disk->delete($scratch);

            report($e);

            return back()->with('toasts', [['type' => 'error', 'message' => 'Backup failed: '.$e->getMessage()]]);
        }

        $disk->move($scratch, $filename);

        $pruned = $this->pruneOldBackups();

        $message = "Backup {$filename} created.";

        if ($pruned > 0) {
            $message .= " {$pruned} older backup(s) removed by the retention policy.";
        }

        return back()->with('toasts', [['type' => 'success', 'message' => $message]]);
    }

    public function download(string $file): StreamedResponse
    {
        $this->authorizePermission('settings.backup.manage');

        $file = $this->safeFilename($file);

        abort_unless(Storage::disk('backups')->exists($file), 404);

        return Storage::disk('backups')->download($file);
    }

    public function destroy(string $file): RedirectResponse
    {
        $this->authorizePermission('settings.backup.manage');

        $file = $this->safeFilename($file);

        abort_unless(Storage::disk('backups')->exists($file), 404);

        Storage::disk('backups')->delete($file);

        return back()->with('toasts', [['type' => 'success', 'message' => 'Backup deleted.']]);
    }

    public function restore(Request $request): RedirectResponse
    {
        $this->authorizePermission('settings.backup.manage');

        $request->validate([
            'backup' => ['required', 'file', 'mimes:sql,txt', 'max:'.$this->maxUploadKb()],
            // A restore is unrecoverable, so it is gated on an explicit typed
            // phrase rather than a single browser confirm() that is trivially
            // dismissed with the wrong tab focused.
            'confirmation' => ['required', 'string', 'in:RESTORE'],
        ]);

        if (! $connection = $this->connection()) {
            return back()->with('toasts', [['type' => 'error', 'message' => $this->unsupportedDriverMessage()]]);
        }

        $disk = Storage::disk('backups');
        $scratch = 'tmp/'.Str::uuid()->toString().'.sql';

        $disk->put($scratch, fopen($request->file('backup')->getRealPath(), 'r'));

        try {
            // Safety net: snapshot the current database before it is overwritten,
            // so a bad upload can be rolled back from the backups list.
            $safety = 'pre-restore-'.now()->format('Y-m-d-His').'-'.Str::random(6).'.sql';
            $safetyScratch = 'tmp/'.Str::uuid()->toString().'.sql';
            $disk->put($safetyScratch, '');

            try {
                $this->runDump($connection, $disk->path($safetyScratch));
                $disk->move($safetyScratch, $safety);
            } catch (\RuntimeException $e) {
                $disk->delete($safetyScratch);

                report($e);

                return back()->with('toasts', [['type' => 'error', 'message' => 'Restore aborted: the pre-restore safety backup failed, so the database was left untouched. '.$e->getMessage()]]);
            }

            try {
                $this->runRestore($connection, $disk->path($scratch));
            } catch (\RuntimeException $e) {
                report($e);

                return back()->with('toasts', [
                    ['type' => 'error', 'message' => 'Restore failed: '.$e->getMessage()],
                    ['type' => 'error', 'message' => "The pre-restore backup {$safety} holds the data that was in the database."],
                ]);
            }
        } finally {
            $disk->delete($scratch);
        }

        // The restored schema is whatever the dump contained, so anything the
        // framework cached from the previous database is now wrong.
        $this->flushCaches();

        // The user row for the current session came from the pre-restore
        // database and may not exist any more. Force a fresh sign-in.
        Auth::logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        // The guest layout renders session('status') rather than the toast
        // stack, so the outcome is flashed as a status message.
        return redirect()->route('login')->with(
            'status',
            "Database restored. The data that was in the database beforehand was saved as {$safety}. Please sign in again."
        );
    }

    /**
     * The active connection's settings, or null when backups cannot be taken.
     *
     * @return array<string, mixed>|null
     */
    protected function connection(): ?array
    {
        return $this->connectionFor(config('database.connections.'.config('database.default')));
    }

    /**
     * Validate a connection definition for use with the MySQL client binaries.
     *
     * Dumping is a mysqldump feature: a SQLite or SQL Server deployment must
     * refuse rather than shell out to a binary that cannot read the schema. An
     * incomplete definition is refused too, because handing mysqldump an empty
     * database argument fails with a confusing client-side error instead.
     *
     * Takes the definition as an argument rather than reading config inline so
     * the rule can be tested directly. Swapping `database.default` in a test to
     * exercise it rebuilds the live connection and leaks a metadata lock that
     * deadlocks the next migrate:fresh.
     *
     * @return array<string, mixed>|null
     */
    protected function connectionFor(mixed $connection): ?array
    {
        if (! is_array($connection) || ($connection['driver'] ?? null) !== 'mysql') {
            return null;
        }

        if (blank($connection['database'] ?? null) || blank($connection['username'] ?? null)) {
            return null;
        }

        return $connection;
    }

    protected function unsupportedDriverMessage(): string
    {
        $driver = config('database.default');

        return "Backups require a MySQL connection; the app is currently using the \"{$driver}\" driver.";
    }

    /**
     * Every .sql dump in the backups root, excluding the tmp/ scratch
     * directory that holds in-flight and uploaded files.
     *
     * @return Collection<int, string>
     */
    protected function backupFiles($disk): Collection
    {
        return collect($disk->allFiles())
            ->filter(fn (string $file): bool => str_ends_with(strtolower($file), '.sql'))
            ->reject(fn (string $file): bool => str_starts_with($file, 'tmp/'))
            ->values();
    }

    /**
     * Number of dumps to keep on disk. Older files are pruned after each
     * successful backup so the directory cannot grow without bound.
     */
    protected function retention(): int
    {
        return max(1, (int) config('backups.keep', 10));
    }

    protected function maxUploadKb(): int
    {
        return max(1, (int) config('backups.max_upload_kb', 512000));
    }

    /**
     * A ceiling in seconds, clamped to something sane so a misconfigured
     * env() value cannot disable the timeout entirely.
     */
    protected function timeout(string $key): int
    {
        return max(30, (int) config('backups.'.$key, 1800));
    }

    protected function pruneOldBackups(): int
    {
        $keep = $this->retention();
        $disk = Storage::disk('backups');

        $files = $this->backupFiles($disk)
            ->map(fn (string $file): array => [$file, $disk->lastModified($file)])
            ->sortByDesc(1)
            ->values();

        $stale = $files->slice($keep)->pluck(0);

        if ($stale->isEmpty()) {
            return 0;
        }

        foreach ($stale as $path) {
            $disk->delete($path);
        }

        return $stale->count();
    }

    /**
     * Reject anything that is not a plain filename in the backups root. The
     * {file} route parameter is user-controlled, and without this a request for
     * "..\..\..\database.sql" would escape the backups directory.
     */
    protected function safeFilename(string $file): string
    {
        $file = basename(str_replace('\\', '/', $file));

        abort_if($file === '' || $file === '.' || $file === '..' || ! str_ends_with(strtolower($file), '.sql'), 404);

        return $file;
    }

    /**
     * Run mysqldump, writing stdout to $target as it arrives rather than
     * collecting it in memory. mysqldump warns on stderr for recoverable
     * conditions (missing PROCESS privilege, GTID notes), so stderr is captured
     * for the error message but is not treated as failure on its own.
     *
     * @param  array<string, mixed>  $connection
     */
    protected function runDump(array $connection, string $target): void
    {
        $binary = $this->resolveDbBinary('mysqldump');

        $command = array_values(array_filter([
            $binary,
            '--single-transaction',
            '--quick',
            '--routines',
            '--triggers',
            '--events',
            '--add-drop-table',
            '--skip-comments',
            // Common on MySQL 8 where the app user lacks PROCESS (to read the
            // tablespace files) and where GTID purging must be left to the host.
            '--no-tablespaces',
            // The live deployment may run MariaDB, whose mysqldump fork has
            // never accepted --set-gtid-purged and dies before a single table
            // is dumped, so the flag travels only with a binary that parses it.
            ...$this->gtidFlag($binary),
            '-h', (string) $connection['host'],
            '-P', (string) ($connection['port'] ?: 3306),
            '-u', (string) $connection['username'],
            // protocolFlag returns a list of flags, so it has to be spread into
            // the command. Passing it as a single element nests an array inside
            // the argv list, and Symfony's Process stringifies every element
            // with strval() — which fatals with "Array to string conversion"
            // before mysqldump is ever executed.
            ...$this->protocolFlag($connection, $binary),
            (string) $connection['database'],
        ]));

        $errors = '';
        $handle = fopen($target, 'w');

        if ($handle === false) {
            throw new \RuntimeException('could not open '.$target.' for writing.');
        }

        $process = new Process($command, null, $this->credentialEnv($connection), null, $this->timeout('dump_timeout'));

        try {
            $process->run(function (string $type, string $buffer) use ($handle, &$errors): void {
                if ($type === Process::ERR) {
                    $errors .= $buffer;

                    return;
                }

                fwrite($handle, $buffer);
            });
        } finally {
            fclose($handle);
        }

        if (! $process->isSuccessful()) {
            throw new \RuntimeException($this->failureMessage('mysqldump', $process, $errors));
        }
    }

    /**
     * Replay a .sql file into the database over the client process' stdin, so
     * the file is never loaded into PHP memory. --force is deliberately NOT
     * used: it suppresses errors and would report success on a dump that only
     * half applied.
     *
     * @param  array<string, mixed>  $connection
     */
    protected function runRestore(array $connection, string $source): void
    {
        $binary = $this->resolveDbBinary('mysql');

        $command = array_values(array_filter([
            $binary,
            '-h', (string) $connection['host'],
            '-P', (string) ($connection['port'] ?: 3306),
            '-u', (string) $connection['username'],
            ...$this->protocolFlag($connection, $binary),
            '--default-character-set=utf8mb4',
            (string) $connection['database'],
        ]));

        $errors = '';
        $input = fopen($source, 'r');

        if ($input === false) {
            throw new \RuntimeException('could not read the uploaded backup file.');
        }

        $process = new Process($command, null, $this->credentialEnv($connection), $input, $this->timeout('restore_timeout'));

        try {
            $process->run(function (string $type, string $buffer) use (&$errors): void {
                if ($type === Process::ERR) {
                    $errors .= $buffer;
                }
            });
        } finally {
            fclose($input);
        }

        if (! $process->isSuccessful()) {
            throw new \RuntimeException($this->failureMessage('mysql', $process, $errors));
        }
    }

    /**
     * Pass the password through MYSQL_PWD rather than -p on the command line,
     * where it is readable by any user listing processes on the host.
     *
     * @param  array<string, mixed>  $connection
     * @return array<string, string>
     */
    protected function credentialEnv(array $connection): array
    {
        $password = (string) ($connection['password'] ?? '');

        return $password === '' ? [] : ['MYSQL_PWD' => $password];
    }

    /**
     * Whether the client binary belongs to MariaDB rather than MySQL.
     *
     * The two forks have drifted apart where option naming is concerned:
     * MariaDB's mysqldump has never accepted --set-gtid-purged, and its
     * mysql client never accepted --ssl-mode (MySQL 8 dropped --ssl in
     * return). An unknown option kills the process before a single byte is
     * dumped or replayed, so the flags must be spelled the way the binary
     * that will parse them understands.
     *
     * The binary announces itself in --version output ("... Ver
     * 10.11.8-MariaDB, ..."), so the *client* is probed, not the server
     * dialect: option syntax belongs to the client parsing them, and a
     * MySQL front-end pointed at a MariaDB server must still get the MySQL
     * spelling. Probed once per process and memoised by path, since the
     * result only depends on which file sits there.
     *
     * A binary that cannot be probed at all (wrong path, broken install) is
     * read as MySQL — the spelling the flags were originally written for.
     * The dump fails either way; this only decides the message.
     */
    protected function clientIsMaria(string $binary): bool
    {
        static $memo = [];

        if (! array_key_exists($binary, $memo)) {
            try {
                $probe = new Process([$binary, '--version'], null, null, null, 10.0);
                $probe->run();

                $memo[$binary] = $probe->isSuccessful()
                    && str_contains($probe->getOutput(), 'MariaDB');
            } catch (\Throwable) {
                // A spawn failure cannot distinguish flavors; default below.
                $memo[$binary] = false;
            }
        }

        return $memo[$binary];
    }

    /**
     * GTID flags for the dump command, decided by the binary in use.
     *
     * MySQL 5.6 stamps every dump with `SET @@GLOBAL.gtid_purged=...` when the
     * source server has GTIDs on, which a restore cannot replay into another
     * GTID-enabled host without SUPER, so real MySQL keeps the explicit OFF.
     * MariaDB never stamped that line in the first place and rejects the
     * option outright ("unknown variable 'set-gtid-purged=OFF'"), so it gets
     * no flag — every backup on such a host currently fails because of it.
     *
     * @param  string  $binary  the resolved mysqldump path/command
     * @return array<int, string>
     */
    protected function gtidFlag(string $binary): array
    {
        return $this->clientIsMaria($binary) ? [] : ['--set-gtid-purged=OFF'];
    }

    /**
     * SSL options for the client, taken from the connection config so a
     * deployment that connects over TLS does not silently dump or restore in
     * plaintext.
     *
     * The REQUIRE spelling again splits by fork: MySQL 8 only understands
     * --ssl-mode (it removed --ssl), MariaDB only understands --ssl (it never
     * gained --ssl-mode), so passing the MySQL spelling under MariaDB would
     * recreate the same refuse-at-parse failure --set-gtid-purged produced.
     *
     * @param  array<string, mixed>  $connection
     * @param  string  $binary  the resolved mysqldump or mysql client
     * @return array<int, string>
     */
    protected function protocolFlag(array $connection, string $binary): array
    {
        $flags = ['--protocol=TCP'];

        $ssl = array_filter([
            $connection['ssl_ca'] ?? null,
            $connection['ssl_cert'] ?? null,
            $connection['ssl_key'] ?? null,
        ]);

        if ($ssl !== []) {
            $flags[] = $this->clientIsMaria($binary) ? '--ssl' : '--ssl-mode=REQUIRED';
        }

        return $flags;
    }

    /**
     * Build an error message from whichever of stderr or exit status carries
     * detail, so the toast is useful rather than a bare non-zero exit.
     */
    protected function failureMessage(string $binary, Process $process, string $capturedStderr): string
    {
        $detail = trim($capturedStderr) ?: trim($process->getErrorOutput());

        if ($detail === '') {
            $detail = $binary.' exited with status '.$process->getExitCode().'.';
        }

        return Str::limit($detail, 200);
    }

    /**
     * Drop caches that were derived from the pre-restore database.
     */
    protected function flushCaches(): void
    {
        foreach (['cache:clear', 'config:clear', 'view:clear', 'optimize:clear'] as $command) {
            try {
                Artisan::call($command);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * Resolve the path to a DB client binary (mysqldump / mysql), working on both
     * Windows (Laragon/XAMPP) and Linux. The configured path is only trusted when
     * the file actually exists, so a Windows path left over on a Linux host is
     * ignored and a usable binary is located instead. Falls back to the bare
     * command name so the system PATH is used when nothing explicit is found.
     *
     * `backups.fallback` turns that auto-detection off for hosts that must not
     * have a binary picked up by surprise — an operator who has deliberately set
     * MYSQLDUMP_PATH and wants backups to fail loudly rather than quietly run
     * through whatever else happens to be installed.
     */
    protected function resolveDbBinary(string $default): string
    {
        $fromConfig = config('backups.'.$default);

        if ($fromConfig && $this->isUsableBinary($fromConfig)) {
            return $fromConfig;
        }

        if (! config('backups.fallback')) {
            // Auto-detection is off, so the configured path is the only
            // candidate. It is returned even though it failed the usability
            // check: handing the process the path the operator actually
            // configured makes the failure name that path, instead of quietly
            // dumping through a different binary or reporting a bare
            // "command not found" for a name they never set.
            return $fromConfig ?: $default;
        }

        $candidates = str_starts_with(PHP_OS, 'WIN')
            ? $this->windowsBinaryCandidates($default)
            : [
                '/usr/bin/'.$default,
                '/usr/local/bin/'.$default,
                '/opt/lampp/bin/'.$default,
                '/usr/local/mysql/bin/'.$default,
            ];

        foreach ($candidates as $candidate) {
            if ($this->isUsableBinary($candidate)) {
                return $candidate;
            }
        }

        return $default;
    }

    protected function isUsableBinary(string $path): bool
    {
        if (! is_file($path)) {
            return false;
        }

        return is_executable($path) || strtolower(substr($path, -4)) === '.exe';
    }

    protected function windowsBinaryCandidates(string $binary): array
    {
        $candidates = ['C:\\xampp\\mysql\\bin\\'.$binary.'.exe'];
        $laragon = 'C:\\laragon\\bin\\mysql';
        if (is_dir($laragon)) {
            foreach (glob($laragon.'\\*', GLOB_ONLYDIR) ?: [] as $dir) {
                $candidates[] = $dir.'\\bin\\'.$binary.'.exe';
            }
        }

        return $candidates;
    }
}
