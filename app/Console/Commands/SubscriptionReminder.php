<?php

namespace App\Console\Commands;

use App\Mail\SubscriptionExpiringSoon;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SubscriptionReminder extends Command
{
    protected $signature = 'subscription:remind
        {--days=7 : Send the reminder when expiry is within this many days}';

    protected $description = 'Notify the super admin when an active package is about to expire.';

    public function handle(): int
    {
        $days = (int) $this->option('days');

        $subscriptions = Subscription::active()
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now())
            ->where('expires_at', '<=', now()->addDays($days))
            ->where(function ($query) use ($days) {
                $query->whereNull('reminder_sent_at')
                    ->orWhere('reminder_sent_at', '<', now()->subDays($days));
            })
            ->get();

        if ($subscriptions->isEmpty()) {
            $this->info("No packages expiring within {$days} days.");
            return self::SUCCESS;
        }

        $recipient = $this->recipient();
        $failures = 0;

        foreach ($subscriptions as $subscription) {
            $daysRemaining = (int) now()->diffInDays($subscription->expires_at, false);

            if ($recipient) {
                try {
                    Mail::to($recipient)->send(new SubscriptionExpiringSoon($subscription, $daysRemaining));
                    $subscription->update(['reminder_sent_at' => now()]);
                    $this->info("Reminder sent for subscription #{$subscription->id} (expires in {$daysRemaining} days).");
                } catch (\Throwable $e) {
                    $failures++;
                    $this->error("Failed to send reminder for subscription #{$subscription->id}: {$e->getMessage()}");
                }
            } else {
                $this->warn("No recipient found for subscription #{$subscription->id}; skipping.");
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    protected function recipient(): ?string
    {
        $superAdmin = User::role('Super Admin')->first();

        if ($superAdmin && $superAdmin->email) {
            return $superAdmin->email;
        }

        return config('mail.from.address');
    }
}
