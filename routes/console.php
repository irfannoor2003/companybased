<?php

use App\Console\Commands\CheckLowStock;
use App\Console\Commands\SubscriptionReminder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
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
