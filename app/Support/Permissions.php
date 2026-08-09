<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class Permissions
{
    /**
     * All module definitions keyed by slug.
     */
    public static function modules(): array
    {
        return config('permissions.modules', []);
    }

    public static function module(string $key): ?array
    {
        return data_get(static::modules(), $key);
    }

    public static function isCore(string $key): bool
    {
        return (bool) data_get(static::module($key), 'core', false);
    }

    /**
     * Build a canonical permission key: <module>.<feature>.<action>
     */
    public static function key(string $module, string $feature, string $action): string
    {
        return Str::lower("{$module}.{$feature}.{$action}");
    }

    /**
     * Every permission key in the registry, grouped as [module][feature][action => key].
     */
    public static function grouped(): array
    {
        $groups = [];

        foreach (static::modules() as $moduleKey => $module) {
            foreach ($module['permissions'] ?? [] as $feature => $actions) {
                foreach ($actions as $action) {
                    $groups[$moduleKey][$feature][$action] = static::key($moduleKey, $feature, $action);
                }
            }
        }

        return $groups;
    }

    /**
     * Flat list of every permission key in the registry.
     */
    public static function all(): array
    {
        return Cache::remember('permissions.registry.keys', now()->addDay(), function () {
            $keys = [];

            foreach (static::modules() as $moduleKey => $module) {
                foreach ($module['permissions'] ?? [] as $feature => $actions) {
                    foreach ($actions as $action) {
                        $keys[] = static::key($moduleKey, $feature, $action);
                    }
                }
            }

            return $keys;
        });
    }

    /**
     * The set of permissions a "view everything / read only" role (HR, Auditor)
     * should hold — every view + export action across the business modules.
     *
     * The `settings` module is intentionally excluded: it holds instance-
     * administration (company, branding, modules, users, roles, backups), not
     * business data, and must stay restricted to Admin / Super Admin. The
     * Auditor is granted the `settings.audit.*` view/export permissions
     * explicitly in RolesSeeder so the audit trail remains reviewable.
     */
    public static function readOnly(): array
    {
        $keys = [];

        foreach (static::modules() as $moduleKey => $module) {
            if ($moduleKey === 'settings') {
                continue;
            }

            foreach ($module['permissions'] ?? [] as $feature => $actions) {
                foreach (array_intersect($actions, ['view', 'export']) as $action) {
                    $keys[] = static::key($moduleKey, $feature, $action);
                }
            }
        }

        return $keys;
    }
}
