<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Reports whether the cron-driven scheduler and queue worker are actually
 * running in production.
 *
 * On shared hosting the queue worker and the scheduler only run when Hostinger's
 * cron fires `schedule:run`. If that cron is missing or the PHP path is wrong,
 * both fail *silently*: no error, jobs simply never execute and queued mail
 * piles up. This command makes that failure visible.
 */
class QueueHealthCheck extends Command
{
    /**
     * Cache key written by the heartbeat closure in routes/console.php.
     */
    public const HEARTBEAT_KEY = 'scheduler:last-run';

    protected $signature = 'queue:health';

    protected $description = 'Report scheduler and queue worker health for shared hosting';

    public function handle(): int
    {
        $failed = false;

        $this->line('<comment>Scheduler</comment>');
        $this->line('  last run: '.$this->lastRun());

        $this->newLine();
        $this->line('<comment>Queues</comment>');

        foreach (['default', 'high'] as $name) {
            $pending = $this->pendingCount($name);
            $backlogged = $pending > 100;

            $this->line(sprintf(
                '  %-9s %d pending%s',
                $name.':',
                $pending,
                $backlogged ? ' <error>(backlog — the worker is not draining)</error>' : '',
            ));

            $failed = $failed || $backlogged;
        }

        $this->newLine();
        $this->line('<comment>Failed jobs</comment>');

        $failedJobs = $this->failedCount();

        $this->line('  '.$failedJobs.($failedJobs > 0 ? ' <error>(see storage/logs/laravel.log)</error>' : ''));

        $failed = $failed || $failedJobs > 0;

        $this->newLine();

        if ($failed) {
            $this->error('Queue health check FAILED. Verify the cron command and the PHP binary path.');

            return self::FAILURE;
        }

        $this->info('Queue health check passed.');

        return self::SUCCESS;
    }

    /**
     * When the scheduler last ran, from the heartbeat written by the
     * `scheduler:heartbeat` closure in routes/console.php.
     *
     * Deliberately not read from the Event objects: Laravel's scheduler does not
     * persist a "last run" per event, so the only reliable signal on shared
     * hosting is a heartbeat the schedule itself writes to the cache.
     */
    private function lastRun(): string
    {
        $at = Cache::get(self::HEARTBEAT_KEY);

        if (! $at) {
            return '<error>never — is the cron entry installed?</error>';
        }

        $age = Carbon::parse($at);

        return $age->diffForHumans().' ('.$age->toDateTimeString().')';
    }

    private function pendingCount(string $queue): int
    {
        if (! $this->queueTableExists()) {
            return 0;
        }

        return DB::table('jobs')->where('queue', $queue)->count();
    }

    private function failedCount(): int
    {
        if (! $this->failedJobsTableExists()) {
            return 0;
        }

        return DB::table('failed_jobs')->count();
    }

    private function queueTableExists(): bool
    {
        return DB::getSchemaBuilder()->hasTable('jobs');
    }

    private function failedJobsTableExists(): bool
    {
        return DB::getSchemaBuilder()->hasTable('failed_jobs');
    }
}
