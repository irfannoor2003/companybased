<?php

namespace Database\Seeders;

use App\Models\NotificationRule;
use Illuminate\Database\Seeder;

/**
 * Default channel configuration per event.
 *
 * Idempotent: existing rows are updated rather than duplicated, so re-running
 * the seeder on a live deployment never creates a second rule for an event.
 * A rule the Super Admin deliberately changed is still respected — only rows
 * that do not exist yet are created here, and disabled rows are left alone.
 */
class NotificationRulesSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            'order.status_changed' => ['label' => 'Order status changed', 'channels' => ['mail']],
            'order.confirmed' => ['label' => 'Order confirmed', 'channels' => ['mail']],
            'delivery.status_changed' => ['label' => 'Delivery note status changed', 'channels' => ['mail']],
            'low_stock' => ['label' => 'Item below reorder level', 'channels' => ['mail', 'database']],
        ];

        foreach ($defaults as $event => $config) {
            NotificationRule::query()->firstOrCreate(
                ['event' => $event],
                [
                    'label' => $config['label'],
                    'channels' => $config['channels'],
                    'enabled' => true,
                ],
            );
        }
    }
}
