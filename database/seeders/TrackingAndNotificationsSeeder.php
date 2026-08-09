<?php

namespace Database\Seeders;

use App\Models\NotificationRule;
use App\Models\SalesOrder;
use App\Services\TrackingService;
use Illuminate\Database\Seeder;

class TrackingAndNotificationsSeeder extends Seeder
{
    public function run(): void
    {
        // Default notification rules for order/delivery events.
        foreach (NotificationRule::availableEvents() as $event => $label) {
            NotificationRule::firstOrCreate(
                ['event' => $event],
                [
                    'label' => $label,
                    'channels' => ['mail'],
                    'enabled' => true,
                ]
            );
        }

        // Backfill tracking codes for every confirmed (non-draft/cancelled) order.
        $tracking = app(TrackingService::class);

        SalesOrder::query()
            ->whereNull('tracking_code')
            ->get()
            ->each(function (SalesOrder $order) use ($tracking) {
                if ($order->isConfirmed()) {
                    $tracking->ensureTrackingCode($order);
                }
            });
    }
}
