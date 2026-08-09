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
        ];
    }
}