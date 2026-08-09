<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Services\AttendanceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Listens for a Feteck-style fingerprint scanner broadcasting over UDP and
 * records clock-in/clock-out for the matching employee.
 *
 * Adapted from the `salesmantrackingsystem` project's ECG/biometric listener:
 * the scanner sends a UDP packet whose 9th byte is the user's ID.
 *
 * Usage:
 *   php artisan attendance:listen [--port=5055]
 */
class AttendanceListenCommand extends Command
{
    protected $signature = 'attendance:listen {--port=5055}';

    protected $description = 'Listen for fingerprint scanner scans and record attendance.';

    public function handle(AttendanceService $service): int
    {
        $port = (int) $this->option('port');

        $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);

        if (! $socket) {
            $this->error('Failed to create UDP socket: '.socket_strerror(socket_last_error()));

            return self::FAILURE;
        }

        if (! @socket_bind($socket, '0.0.0.0', $port)) {
            $this->error('Failed to bind socket: '.socket_strerror(socket_last_error()));

            return self::FAILURE;
        }

        $this->info("Listening for fingerprint scans (IDs) on UDP port {$port}...");

        while (true) {
            @socket_recvfrom($socket, $buf, 1024, 0, $from, $fromPort);

            $bytes = unpack('C*', $buf);
            $id = $bytes[9] ?? 0;

            // 204 is a status heartbeat from the scanner — ignore it.
            if ($id <= 0 || $id === 204) {
                continue;
            }

            $cacheKey = "bio_scan_{$id}";

            if (Cache::has($cacheKey)) {
                continue;
            }

            try {
                $employee = Employee::query()
                    ->where('attendance_enabled', true)
                    ->where(fn ($q) => $q->where('user_id', $id)->orWhere('employee_code', (string) $id))
                    ->first();

                if (! $employee) {
                    $this->warn("Unknown scan: ID {$id} ({$from}).");

                    Cache::put($cacheKey, true, 5);
                    continue;
                }

                $result = $service->recordScan($employee, 'fingerprint');

                if ($result['status'] === 'success') {
                    $this->info("✓ {$employee->employee_code} — ".($result['type'] === 'in' ? 'clock-in' : 'clock-out'));
                } else {
                    $this->error("✕ {$employee->employee_code}: {$result['message']}");
                }
            } catch (Throwable $e) {
                $this->error('Error: '.$e->getMessage());
            }

            Cache::put($cacheKey, true, 20);
        }

        return self::SUCCESS;
    }
}