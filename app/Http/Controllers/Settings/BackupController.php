<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class BackupController extends Controller
{
    public function __construct()
    {
        $this->authorizePermission('settings.backup.manage');
    }

    /**
     * Resolve the path to a DB client binary (mysqldump / mysql), working on both
     * Windows (Laragon/XAMPP) and Linux. The configured env path is only trusted
     * when the file actually exists, so a Windows path left over on a Linux host
     * is ignored and a usable binary is located instead. Falls back to the bare
     * command name so the system PATH is used when nothing explicit is found.
     */
    protected function resolveDbBinary(string $envKey, string $default): string
    {
        $fromEnv = env($envKey);
        if ($fromEnv && $this->isUsableBinary($fromEnv)) {
            return $fromEnv;
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

    public function index()
    {
        $backups = collect(Storage::disk('backups')->allFiles())
            ->map(fn ($file) => [
                'path' => $file,
                'name' => basename($file),
                'size' => Storage::disk('backups')->size($file),
                'modified' => Storage::disk('backups')->lastModified($file),
            ])
            ->sortByDesc('modified')
            ->values();

        return view('settings.backups', compact('backups'));
    }

    public function create(): RedirectResponse
    {
        $connection = config('database.connections.mysql');
        $mysqldump = $this->resolveDbBinary('MYSQLDUMP_PATH', 'mysqldump');

        $filename = 'backup-'.now()->format('Y-m-d-His').'.sql';
        $path = storage_path('app/backups/'.$filename);

        $command = array_values(array_filter([
            $mysqldump,
            '--single-transaction',
            '--routines',
            '--skip-comments',
            '-u', $connection['username'],
            $connection['password'] ? ('-p'.$connection['password']) : null,
            '-h', $connection['host'],
            '-P', (string) $connection['port'],
            $connection['database'],
        ]));

        $process = new Process($command, null, null, null, 300);

        try {
            $process->mustRun();
        } catch (ProcessFailedException $e) {
            report($e);

            return back()->with('toasts', [['type' => 'error', 'message' => 'Backup failed: '.Str::limit($process->getErrorOutput(), 200)]]);
        }

        Storage::disk('backups')->put($filename, $process->getOutput());

        return back()->with('toasts', [['type' => 'success', 'message' => "Backup {$filename} created."]]);
    }

    public function download(string $file): StreamedResponse
    {
        abort_unless(Storage::disk('backups')->exists($file), 404);

        return Storage::disk('backups')->download($file);
    }

    public function destroy(string $file): RedirectResponse
    {
        abort_unless(Storage::disk('backups')->exists($file), 404);

        Storage::disk('backups')->delete($file);

        return back()->with('toasts', [['type' => 'success', 'message' => 'Backup deleted.']]);
    }

    public function restore(Request $request): RedirectResponse
    {
        $request->validate([
            'backup' => ['required', 'file', 'mimes:sql,txt', 'max:102400'],
        ]);

        $connection = config('database.connections.mysql');
        $mysql = $this->resolveDbBinary('MYSQL_CLIENT_PATH', 'mysql');

        $tempPath = $request->file('backup')->store('tmp/restore-'.Str::random(8).'.sql', 'local');

        $command = array_values(array_filter([
            $mysql,
            '-u', $connection['username'],
            $connection['password'] ? ('-p'.$connection['password']) : null,
            '-h', $connection['host'],
            '-P', (string) $connection['port'],
            $connection['database'],
        ]));

        $process = new Process($command, null, null, fopen(storage_path('app/'.$tempPath), 'r'), 600);

        try {
            $process->mustRun();
        } catch (ProcessFailedException $e) {
            report($e);

            return back()->with('toasts', [['type' => 'error', 'message' => 'Restore failed: '.Str::limit($process->getErrorOutput(), 200)]]);
        } finally {
            Storage::delete($tempPath);
        }

        return back()->with('toasts', [['type' => 'success', 'message' => 'Database restored successfully.']]);
    }
}
