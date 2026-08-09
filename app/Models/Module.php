<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class Module extends Model
{
    protected $fillable = [
        'key', 'label', 'icon', 'description', 'sort_order', 'is_core', 'enabled',
    ];

    protected function casts(): array
    {
        return [
            'is_core' => 'boolean',
            'enabled' => 'boolean',
        ];
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    /**
     * The list of enabled module keys, cached.
     */
    public static function enabledKeys(): array
    {
        return Cache::remember('modules.enabled.keys', now()->addHour(), function () {
            return static::query()->where('enabled', true)->pluck('key')->all();
        });
    }

    public static function isEnabled(string $key): bool
    {
        return in_array($key, static::enabledKeys(), true);
    }

    public static function flushCache(): void
    {
        Cache::forget('modules.enabled.keys');
    }

    /**
     * Synchronise the modules table with the config/permissions.php registry.
     * Returns true when anything changed.
     */
    public static function syncFromRegistry(): bool
    {
        $changed = false;

        DB::transaction(function () use (&$changed) {
            $keys = [];
            $sort = 0;

            foreach (config('permissions.modules', []) as $key => $def) {
                $keys[] = $key;
                $sort++;

                $module = static::firstOrNew(['key' => $key]);

                $module->label = $def['label'];
                $module->icon = $def['icon'] ?? null;
                $module->description = $def['description'] ?? null;
                $module->sort_order = $def['sort_order'] ?? $sort;
                $module->is_core = $def['core'] ?? false;
                $module->enabled = $module->exists ? $module->enabled : ($def['default_enabled'] ?? true);

                if ($module->isDirty()) {
                    $module->save();
                    $changed = true;
                }
            }

            $removed = static::query()->whereNotIn('key', $keys)->delete();
            if ($removed > 0) {
                $changed = true;
            }
        });

        static::flushCache();

        return $changed;
    }
}
