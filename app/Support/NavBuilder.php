<?php

namespace App\Support;

use App\Models\Module;
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

        return collect(config('nav.items', []))->map(function (array $item) use ($user, $enabledModuleKeys, $attendanceTracked) {
            $moduleEnabled = self::moduleEnabled($item, $enabledModuleKeys);

            if (! empty($item['group'])) {
                $children = collect($item['items'] ?? [])
                    ->filter(fn ($child) => self::visible($child, $user, $enabledModuleKeys, $attendanceTracked))
                    ->values();

                return [
                    'item' => $item,
                    'visible' => $moduleEnabled && $children->isNotEmpty(),
                    'children' => $children,
                ];
            }

            return [
                'item' => $item,
                'visible' => $moduleEnabled && self::visible($item, $user, $enabledModuleKeys, $attendanceTracked),
            ];
        });
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