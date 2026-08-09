<?php

namespace App\Models;

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
        foreach ($values as $key => $value) {
            static::updateOrCreate(
                ['key' => $key],
                ['value' => is_scalar($value) ? (string) $value : json_encode($value), 'group' => $group]
            );
        }

        static::flushCache();
    }

    private static function cached(): array
    {
        return Cache::remember('settings.all', now()->addHour(), function () {
            return static::query()->pluck('value', 'key')->all();
        });
    }

    public static function flushCache(): void
    {
        Cache::forget('settings.all');
    }
}
