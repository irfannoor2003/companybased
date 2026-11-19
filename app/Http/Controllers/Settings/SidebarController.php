<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Module;
use App\Models\Role;
use App\Models\SidebarPreference;
use App\Support\NavBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Admin-configurable sidebar links.
 *
 * Two scopes, one set of rules:
 *  - a role's defaults, edited by an admin in Settings -> Sidebar
 *  - a user's own choices, edited at /sidebar/customize
 *
 * POST values are always whitelisted against keys derived from config/nav.php,
 * so a crafted request cannot inject an arbitrary key. Custom links get
 * server-generated keys and their URLs are restricted to relative paths or
 * http(s) — no javascript: or data: schemes. Neither scope can reveal a link
 * the user's permissions would hide; NavBuilder applies those gates at render.
 */
class SidebarController extends Controller
{
    public function personal(): View
    {
        $user = auth()->user();
        $preferences = SidebarPreference::where('user_id', $user->id)->orderBy('sort_order')->get()->keyBy('nav_key');

        return view('sidebar.customize', [
            'preferences' => $preferences,
            'entries' => $this->entries(null, $user),
            'rolePreferences' => $this->rolePreferencesFor($user),
            'scope' => 'personal',
            'action' => route('sidebar.customize.update'),
        ]);
    }

    public function updatePersonal(Request $request): RedirectResponse
    {
        $user = auth()->user();

        $this->save($request, ['user_id' => $user->id, 'role_id' => null]);

        return redirect()->route('sidebar.customize')
            ->with('toasts', [['type' => 'success', 'message' => 'Your sidebar has been updated.']]);
    }

    public function resetPersonal(): RedirectResponse
    {
        SidebarPreference::where('user_id', auth()->id())->delete();

        return redirect()->route('sidebar.customize')
            ->with('toasts', [['type' => 'success', 'message' => 'Your sidebar was reset to your role defaults.']]);
    }

    public function overview(): RedirectResponse
    {
        $this->authorizePermission('settings.sidebar.view');
        $this->ensureNotSuperAdmin();

        $role = Role::where('name', '!=', config('roles.super_admin'))->orderBy('name')->firstOrFail();

        return redirect()->route('settings.sidebar.edit', $role);
    }

    public function edit(Role $role): View
    {
        $this->authorizePermission('settings.sidebar.view');
        $this->ensureNotSuperAdmin();
        $this->ensureCompanyRole($role);

        return view('settings.sidebar.edit', [
            'role' => $role,
            'roles' => Role::where('name', '!=', config('roles.super_admin'))->orderBy('name')->get(),
            'preferences' => $role->sidebarPreferences()->get()->keyBy('nav_key'),
            'rolePreferences' => collect(),
            'entries' => $this->entries($role),
            'scope' => 'role',
            'action' => route('settings.sidebar.update', $role),
        ]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $this->authorizePermission('settings.sidebar.manage');
        $this->ensureNotSuperAdmin();
        $this->ensureCompanyRole($role);

        $this->save($request, ['user_id' => null, 'role_id' => $role->id]);

        return back()->with('toasts', [['type' => 'success', 'message' => "Sidebar for {$role->name} updated."]]);
    }

    /**
     * Only the links the edited scope can actually reach.
     *
     * Showing a toggle for a link the role has no permission for is a lie: the
     * link can never appear however it is ticked. So the editor applies the same
     * gates as NavBuilder — module enabled and permission held — before listing
     * anything. For a role we test against that role's permissions; for the
     * personal editor against the signed-in user. This list is also the save
     * whitelist, so a crafted POST cannot store a key the scope cannot use.
     *
     * @return array<int,array{key:string,label:string,group:bool}>
     */
    private function entries(?Role $role = null, ?\App\Models\User $user = null): array
    {
        $user ??= auth()->user();
        $enabled = Module::enabledKeys();
        $entries = [];

        foreach (config('nav.items', []) as $item) {
            $module = $item['module'] ?? null;

            if ($module && ! in_array($module, $enabled, true)) {
                continue;
            }

            if (! empty($item['group'])) {
                $children = [];

                foreach ($item['items'] ?? [] as $child) {
                    if (! $this->allowed($child, $role, $user)) {
                        continue;
                    }

                    $children[] = [
                        'key' => NavBuilder::navKey($child),
                        'label' => $child['label'],
                        'group' => false,
                    ];
                }

                // A section with nothing reachable must not be offered at all.
                if ($children === []) {
                    continue;
                }

                $entries[] = [
                    'key' => NavBuilder::navKey($item),
                    'label' => $item['group'],
                    'group' => true,
                ];

                foreach ($children as $child) {
                    $entries[] = $child;
                }

                continue;
            }

            if (! $this->allowed($item, $role, $user)) {
                continue;
            }

            $entries[] = [
                'key' => NavBuilder::navKey($item),
                'label' => $item['label'],
                'group' => false,
            ];
        }

        return $entries;
    }

    /**
     * Same permission rule NavBuilder uses, scoped to the role being edited or
     * to the current user when editing personal preferences.
     *
     * @param  array<string,mixed>  $item
     */
    private function allowed(array $item, ?Role $role, ?\App\Models\User $user): bool
    {
        $permission = $item['permission'] ?? null;

        if (! $permission) {
            return true;
        }

        if ($role) {
            return $role->permissions->contains('name', $permission);
        }

        return $user?->can($permission) === true;
    }

    /**
     * Replace the rows for one scope with the submitted choices.
     *
     * @param  array<string,int|null>  $scope
     */
    private function save(Request $request, array $scope): void
    {
        $data = $request->validate([
            'visible' => ['nullable', 'array'],
            'visible.*' => ['string'],
            'custom' => ['nullable', 'array'],
            'custom.*.label' => ['required_with:custom.*.url', 'string', 'max:80'],
            'custom.*.url' => ['required_with:custom.*.label', 'string', 'max:500'],
            'custom.*.icon' => ['nullable', 'string', 'max:50'],
        ]);

        $role = $scope['role_id'] !== null ? Role::findOrFail($scope['role_id']) : null;
        $allowed = collect($this->entries($role))->pluck('key')->all();
        $visible = array_values(array_intersect($data['visible'] ?? [], $allowed));
        $custom = collect($data['custom'] ?? [])->filter(
            fn ($link) => is_array($link) && $this->safeUrl((string) ($link['url'] ?? '')) !== null,
        )->values();

        $column = $scope['user_id'] !== null ? 'user_id' : 'role_id';

        DB::transaction(function () use ($scope, $column, $allowed, $visible, $custom): void {
            SidebarPreference::where($column, $scope[$column])->delete();

            foreach ($allowed as $index => $key) {
                SidebarPreference::create($scope + [
                    'nav_key' => $key,
                    'sort_order' => $index,
                    'is_visible' => in_array($key, $visible, true),
                ]);
            }

            foreach ($custom as $index => $link) {
                SidebarPreference::create($scope + [
                    'nav_key' => 'custom:'.($index + 1),
                    'label' => $link['label'],
                    'url' => $this->safeUrl($link['url']),
                    'icon' => $this->safeIcon($link['icon'] ?? null),
                    'sort_order' => count($allowed) + $index,
                    'is_visible' => true,
                ]);
            }
        });
    }

    /**
     * Relative paths and http(s) URLs only. Anything else (javascript:, data:,
     * vbscript:, protocol-relative ambiguity) is refused rather than stored.
     */
    private function safeUrl(string $url): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return $url;
        }

        if (preg_match('#^https?://[^\s]+$#i', $url) === 1) {
            return $url;
        }

        return null;
    }

    /**
     * Only icon keys the icon component actually knows; unknown names render a
     * blank glyph, so refuse them at the form boundary.
     */
    private function safeIcon(?string $icon): ?string
    {
        if (! $icon || preg_match('/^[a-z0-9-]+$/', $icon) !== 1) {
            return null;
        }

        $source = (string) file_get_contents(resource_path('views/components/icon.blade.php'));

        return str_contains($source, "'{$icon}'") ? $icon : null;
    }

    /**
     * Defaults the personal editor shows when a user has no rows of their own.
     */
    private function rolePreferencesFor($user): \Illuminate\Support\Collection
    {
        $roleIds = $user->roles->pluck('id')->all();

        return $roleIds === []
            ? collect()
            : SidebarPreference::whereIn('role_id', $roleIds)->get()->keyBy('nav_key');
    }

    private function ensureCompanyRole(Role $role): void
    {
        abort_if($role->name === config('roles.super_admin'), 404);
    }

    private function ensureNotSuperAdmin(): void
    {
        abort_if(auth()->user()?->isSuperAdmin(), 404);
    }
}
