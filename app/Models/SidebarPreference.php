<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One admin's or one role's choice about a single sidebar link.
 *
 * Custom links have a nav_key of the form `custom:<n>` and carry label, url and
 * icon. Built-in links use their route name (or `group:<name>` for a heading)
 * and only need is_visible.
 */
class SidebarPreference extends Model
{
    protected $fillable = [
        'user_id', 'role_id', 'nav_key', 'label', 'url', 'icon', 'sort_order', 'is_visible',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_visible' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function isCustom(): bool
    {
        return str_starts_with((string) $this->nav_key, 'custom:');
    }
}
