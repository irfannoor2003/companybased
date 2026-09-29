<?php

namespace App\Models;

use App\Support\Branding;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $fillable = ['key', 'value', 'group', 'is_public'];

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
        ];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $values = static::cached();

        return $values[$key] ?? $default;
    }

    public static function set(string $key, mixed $value, string $group = 'general', bool $isPublic = false): void
    {
        static::updateOrCreate(
            ['key' => $key],
            ['value' => is_scalar($value) ? (string) $value : json_encode($value), 'group' => $group, 'is_public' => $isPublic]
        );

        static::flushCache();
    }

    public static function forget(string $key): void
    {
        static::where('key', $key)->delete();
        static::flushCache();
    }

    public static function setMany(array $values, string $group = 'general'): void
    {
        if ($values === []) {
            return;
        }

        $now = now();
        $rows = [];

        foreach ($values as $key => $value) {
            $rows[] = [
                'key' => $key,
                'value' => is_scalar($value) ? (string) $value : json_encode($value),
                'group' => $group,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // A single upsert instead of one updateOrCreate per key. SettingsSeeder
        // writes 35 keys, and it runs on every test, so the per-row SELECT +
        // UPDATE + INSERT was pure overhead.
        static::query()->upsert($rows, ['key'], ['value', 'group', 'updated_at']);

        static::flushCache();
    }

    private static ?array $memo = null;

    private static function cached(): array
    {
        if (static::$memo !== null) {
            return static::$memo;
        }

        return static::$memo = Cache::remember('settings.all', now()->addHour(), function () {
            return static::query()->pluck('value', 'key')->all();
        });
    }

    public static function flushCache(): void
    {
        static::$memo = null;

        Cache::forget('settings.all');

        // The per-request brand payload is derived from settings, so it has to be
        // discarded too or an edit would not show until the next request.
        Branding::flush();
    }

    /**
     * Write a key straight into the in-request memo.
     *
     * Used after a direct (non-Setting::set) write. Without this the memo keeps
     * serving the pre-write value, so anything reading the key back through
     * get() during the same request sees stale data.
     */
    public static function primeMemo(string $key, mixed $value): void
    {
        if (static::$memo !== null) {
            static::$memo[$key] = $value;
        }
    }
}
