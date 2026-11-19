<?php

namespace App\Support;

use App\Models\Module;
use App\Models\SidebarPreference;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Single source of truth for which sidebar entries a user may see.
 *
 * This logic used to live in a 59-line `@php` block at the top of
 * `layouts/partials/sidebar.blade.php`, which meant the visibility rule had two
 * problems. It was untestable without rendering a full page, and it was a fourth
 * implementation of the same authorization decision already expressed by route
 * middleware and `Controller::authorizePermission()`. The rule is identical here:
 * an entry is shown when its module is enabled AND the user holds its permission,
 * and a group is shown only when at least one child survives.
 *
 * Nothing here is a security boundary. `can()` returning false for an unknown
 * permission withholds the link silently, which is exactly why the census
 * ViewIntegrityTest fails the build on a permission string that is not in the
 * registry. The real enforcement stays in the route middleware.
 */
final class NavBuilder
{
    /**
     * The resolved navigation tree for a user.
     *
     * @return \Illuminate\Support\Collection<int, array{visible: bool, item: array<string, mixed>, children?: \Illuminate\Support\Collection<int, array<string, mixed>>}>
     */
    public static function items(?User $user, ?array $enabledModuleKeys = null): Collection
    {
        $user ??= auth()->user();
        $enabledModuleKeys ??= Module::enabledKeys();
        $attendanceTracked = (bool) $user?->isAttendanceTracked();
        $preferences = self::preferencesFor($user);

        $items = collect(config('nav.items', []))->map(function (array $item) use ($user, $enabledModuleKeys, $attendanceTracked, $preferences) {
            $moduleEnabled = self::moduleEnabled($item, $enabledModuleKeys);
            $allowed = self::preferenceAllows($preferences, self::navKey($item));

            if (! empty($item['group'])) {
                $children = collect($item['items'] ?? [])
                    ->filter(fn ($child) => self::visible($child, $user, $enabledModuleKeys, $attendanceTracked)
                        && self::preferenceAllows($preferences, self::navKey($child)))
                    ->values();

                return [
                    'item' => $item,
                    'visible' => $moduleEnabled && $allowed && $children->isNotEmpty(),
                    'children' => $children,
                ];
            }

            return [
                'item' => $item,
                'visible' => $moduleEnabled && $allowed && self::visible($item, $user, $enabledModuleKeys, $attendanceTracked),
            ];
        });

        /*
         * Operator-defined custom links are stored as preference rows rather
         * than in config/nav.php, so they can be added without a deploy. They
         * get their own labelled "Custom Links" section instead of being mixed
         * into the built-in entries, so an operator can tell at a glance which
         * links are theirs. Access is inherent in who the row was saved for;
         * the ordinary gates above still apply to every built-in link, so a
         * preference can only ever HIDE a link, never reveal one.
         */
        $custom = collect();

        foreach ($preferences as $preference) {
            if (! $preference->isCustom() || ! $preference->is_visible || ! $preference->url) {
                continue;
            }

            $custom->push([
                'label' => $preference->label ?: 'Link',
                'icon' => $preference->icon ?: 'globe',
                'url' => $preference->url,
                'custom' => true,
            ]);
        }

        if ($custom->isNotEmpty()) {
            $items->push([
                'item' => [
                    'group' => 'Custom Links',
                    'module' => null,
                ],
                'visible' => true,
                'children' => $custom,
            ]);
        }

        return $items;
    }

    /**
     * The stable key a nav entry is stored under for a preference row.
     * Routes are unique across the nav; headings get an explicit prefix.
     */
    public static function navKey(array $item): string
    {
        if (! empty($item['group'])) {
            return 'group:'.$item['group'];
        }

        return (string) ($item['route'] ?? '');
    }

    /**
     * Role defaults, overlaid per key by the user's own choices. A user row
     * therefore overrides the role only for the entries it names; everything
     * else keeps following the role.
     *
     * @return Collection<string, SidebarPreference>
     */
    private static function preferencesFor(?User $user): Collection
    {
        if (! $user) {
            return collect();
        }

        $roleIds = $user->roles->pluck('id')->all();
        $rolePreferences = $roleIds === []
            ? collect()
            : SidebarPreference::whereIn('role_id', $roleIds)->orderBy('sort_order')->get()->keyBy('nav_key');
        $userPreferences = SidebarPreference::where('user_id', $user->id)->orderBy('sort_order')->get()->keyBy('nav_key');

        // Eloquent\Collection::merge() re-keys by the models' primary keys,
        // which destroys the nav_key keys we just built. toBase() keeps them.
        return $rolePreferences->toBase()->merge($userPreferences->toBase());
    }

    /**
     * No row means the default: visible. An explicit row can only switch it off.
     */
    private static function preferenceAllows(Collection $preferences, string $key): bool
    {
        $preference = $preferences->get($key);

        return ! $preference || $preference->is_visible;
    }

    /**
     * Whether $route should render as the active sidebar entry.
     *
     * A `.index` route name stands for its whole section, so `sales.invoices.index`
     * must stay highlighted on `sales.invoices.show` — otherwise opening a record
     * drops the highlight and the user loses their place in the tree.
     */
    public static function isActive(?string $route): bool
    {
        $current = request()->route()?->getName();

        if (! $current || ! $route) {
            return false;
        }

        if ($current === $route) {
            return true;
        }

        if (str_starts_with($current, $route.'.')) {
            return true;
        }

        $segments = explode('.', $route);

        if (end($segments) === 'index') {
            array_pop($segments);

            return str_starts_with($current, implode('.', $segments).'.');
        }

        return false;
    }

    /**
     * Whether the entry's module is enabled. An entry with no module key is always
     * enabled — it is not owned by any module, so there is nothing to switch off.
     *
     * @param  array<string, mixed>  $enabledModuleKeys
     */
    private static function moduleEnabled(array $item, array $enabledModuleKeys): bool
    {
        $module = $item['module'] ?? null;

        return ! $module || in_array($module, $enabledModuleKeys, true);
    }

    /**
     * Permission and employee-only gates for a single entry.
     *
     * `employee_only` exists because the self-service HR screens act on an
     * `employees` row, so a user without one has nothing to show. Attendance
     * tracking is the existing proxy for "has an employee profile", and it is what
     * the sidebar used to consult.
     *
     * @param  array<string, mixed>  $enabledModuleKeys
     */
    private static function visible(array $item, ?User $user, array $enabledModuleKeys, bool $attendanceTracked): bool
    {
        $module = $item['module'] ?? null;

        if ($module && ! in_array($module, $enabledModuleKeys, true)) {
            return false;
        }

        if (($item['permission'] ?? null) && $user?->can($item['permission']) !== true) {
            return false;
        }

        if (($item['employee_only'] ?? false) && ! $attendanceTracked) {
            return false;
        }

        return true;
    }
}