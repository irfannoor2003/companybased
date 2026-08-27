<?php

use App\Console\Commands\SubscriptionReminder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily check for packages about to expire (sends a reminder email ~7 days out).
Schedule::command(SubscriptionReminder::class)->daily();
