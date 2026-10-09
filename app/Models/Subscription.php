<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    /**
     * The memoised "is access blocked" verdict.
     *
     * CheckSubscription runs on every authenticated request and this method is the
     * only thing standing between an expired package and the rest of the
     * application, so it was issuing two queries per page load. Setting and Module
     * memoise the same way for the same reason.
     *
     * Memoised rather than cached in the container so it cannot outlive a single
     * request in a queue worker, and flushed explicitly by the two actions that
     * change a subscription (see flushCache call sites).
     */
    private static ?bool $accessBlockedMemo = null;

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
     * Discard the memoised verdict.
     *
     * Required after writing a subscription directly, otherwise the change is not
     * visible until the process ends. The subscription settings screen calls this;
     * tests that change subscription state between assertions must too.
     */
    public static function flushCache(): void
    {
        self::$accessBlockedMemo = null;
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
     *
     * Note the asymmetry with isAccessBlocked(): no subscription at all means
     * *not* expired, but it does mean access is blocked. Seeding creates a
     * subscription precisely so staff routes are reachable.
     */
    public static function isExpired(): bool
    {
        $sub = static::current();

        return $sub !== null && $sub->expires_at !== null && $sub->expires_at->isPast();
    }

    /**
     * True when staff access must be blocked: either there is no active
     * package at all, or the active package has expired. The Super Admin is
     * exempt and may always reach the panel to (re)activate a package.
     */
    public static function isAccessBlocked(): bool
    {
        if (self::$accessBlockedMemo !== null) {
            return self::$accessBlockedMemo;
        }

        $sub = static::current();

        return self::$accessBlockedMemo = (
            $sub === null
            || $sub->expires_at === null
            || $sub->expires_at->isPast()
        );
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
