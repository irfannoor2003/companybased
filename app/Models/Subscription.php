<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    protected $fillable = [
        'plan_name', 'starts_at', 'expires_at', 'is_active', 'reminder_sent_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The currently active subscription (the one controlling access).
     */
    public static function current(): ?self
    {
        return static::active()->latest('expires_at')->first();
    }

    /**
     * True only when an active subscription exists and its expiry has passed.
     * If there is no active subscription the app is treated as unrestricted.
     */
    public static function isExpired(): bool
    {
        $sub = static::current();

        return $sub !== null && $sub->expires_at !== null && $sub->expires_at->isPast();
    }

    public static function daysRemaining(): ?int
    {
        $sub = static::current();

        if (! $sub || ! $sub->expires_at) {
            return null;
        }

        return (int) now()->diffInDays($sub->expires_at, false);
    }
}
