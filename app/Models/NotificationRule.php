<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationRule extends Model
{
    protected $fillable = [
        'event', 'label', 'channels', 'enabled', 'subject', 'message',
    ];

    protected function casts(): array
    {
        return [
            'channels' => 'array',
            'enabled' => 'boolean',
        ];
    }

    public static function forEvent(string $event): ?self
    {
        return static::query()->where('event', $event)->where('enabled', true)->first();
    }

    public static function availableEvents(): array
    {
        return [
            'order.status_changed' => 'Order status changed',
            'order.confirmed' => 'Order confirmed',
            'delivery.status_changed' => 'Delivery note status changed',
            'low_stock' => 'Item below reorder level',
        ];
    }

    /**
     * Events that are dispatched on a fixed schedule or by the stock ledger
     * rather than through NotificationService::channelsFor(), so they always
     * fall back to mail + in-app. Listed here for the settings UI only.
     */
    public static function selfManagedEvents(): array
    {
        return [
            'low_stock',
        ];
    }
}
