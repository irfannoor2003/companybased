<?php

use App\Console\Commands\CheckLowStock;
use App\Console\Commands\QueueHealthCheck;
use App\Console\Commands\SubscriptionReminder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily check for packages about to expire (sends a reminder email ~7 days out).
Schedule::command(SubscriptionReminder::class)->daily();

// Backstop sweep for low stock. The ledger already alerts on the movement that
// takes an item below its reorder level; this catches anything that changed
// without going through it (direct SQL, imports, a missed job).
Schedule::command(CheckLowStock::class)->dailyAt('07:15');

/*
|--------------------------------------------------------------------------
| Queue worker
|--------------------------------------------------------------------------
|
| Hostinger shared hosting has no Supervisor, so `queue:work` cannot be kept
| alive as a daemon. Instead a single cron entry runs `schedule:run` every
| minute, and the worker is scheduled here to process whatever is waiting and
| then exit cleanly.
|
| Hostinger's hPanel "PHP" cron type does not accept `>` or `&&`, so the cron
| command must be entered without output redirection or you will get a parse
| error. Use a Custom cron job with a .sh wrapper if you need the redirection.
|
| Consequences to be aware of:
|  - Throughput is capped at one drain per minute, so a burst of queued mail is
|    spread over several minutes rather than sent at once.
|  - `--max-time` stops a worker that has been running too long, which bounds
|    PHP CLI memory growth on shared hosting.
|  - `--timeout` must stay under the host's CLI execution cap (commonly
|    120-300s) or a long job such as a dompdf render is killed mid-flight.
|
| Verify the PHP binary path in hTerminal before trusting this. On Hostinger
| the CLI binary is usually /opt/alt/php82/usr/bin/php, not /usr/bin/php.
|
*/
Schedule::command('queue:work', [
    '--stop-when-empty' => true,
    '--tries' => 3,
    '--timeout' => 90,
    '--max-time' => 240,
    '--sleep' => 1,
])->everyMinute()->withoutOverlapping(10);

/*
|--------------------------------------------------------------------------
| Heartbeat
|--------------------------------------------------------------------------
|
| Writes a timestamp to the cache on every schedule:run. Laravel does not keep
| a per-event "last run" time, so on shared hosting this is the only reliable way
| to tell whether the cron entry is actually firing — a missing cron or wrong
| PHP path fails silently, leaving jobs queued forever with nothing to show for
| it. `php artisan queue:health` reads this key.
|
*/
Schedule::call(function () {
    Cache::put(QueueHealthCheck::HEARTBEAT_KEY, now()->toIso8601String(), now()->addDay());
})->everyMinute()->name('scheduler-heartbeat');
