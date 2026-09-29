<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\DashboardWidgetPreference;
use App\Models\Module;
use App\Models\Role;
use App\Support\DashboardWidgetRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardPreferenceController extends Controller
{
    public function overview(): RedirectResponse
    {
        $this->authorizePermission('settings.dashboard.view');
        $this->ensureNotSuperAdmin();

        $role = Role::query()->where('name', '!=', config('roles.super_admin'))->orderBy('name')->firstOrFail();

        return redirect()->route('settings.dashboards.edit', $role);
    }

    public function index(Role $role): View
    {
        $this->authorizePermission('settings.dashboard.view');
        $this->ensureNotSuperAdmin();
        $this->ensureCompanyRole($role);

        $roles = Role::query()->where('name', '!=', config('roles.super_admin'))->orderBy('name')->get();
        $definitions = DashboardWidgetRegistry::forRole($role);
        $preferences = $role->dashboardWidgets()->get()->keyBy('widget_key');

        return view('settings.dashboards.index', compact('role', 'roles', 'definitions', 'preferences'));
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $this->authorizePermission('settings.dashboard.manage');
        $this->ensureNotSuperAdmin();
        $this->ensureCompanyRole($role);

        $data = $request->validate([
            'widgets' => ['nullable', 'array'],
            'widgets.*' => ['string'],
        ]);

        $allowed = array_keys(DashboardWidgetRegistry::forRole($role));
        $selected = array_values(array_intersect($data['widgets'] ?? [], $allowed));

        DB::transaction(function () use ($role, $allowed, $selected): void {
            $role->dashboardWidgets()->delete();
            $role->dashboardWidgets()->createMany(collect($allowed)->map(fn (string $key, int $index): array => [
                'widget_key' => $key,
                'sort_order' => $index,
                'is_visible' => in_array($key, $selected, true),
            ])->all());
        });

        return back()->with('toasts', [['type' => 'success', 'message' => "Dashboard layout for {$role->label} updated."]]);
    }

    public function personal(): View
    {
        $user = auth()->user();
        $definitions = collect(DashboardWidgetRegistry::definitions())
            ->filter(fn (array $definition): bool => $user->can($definition['permission'])
                && Module::isEnabled(explode('.', $definition['permission'])[0]))
            ->all();
        $preferences = DashboardWidgetPreference::query()->where('user_id', $user->id)->orderBy('sort_order')->get()->keyBy('widget_key');

        return view('dashboard.customize', compact('definitions', 'preferences'));
    }

    public function updatePersonal(Request $request): RedirectResponse
    {
        $user = auth()->user();
        $data = $request->validate([
            'widgets' => ['nullable', 'array'],
            'widgets.*' => ['string'],
        ]);
        $allowed = array_keys(collect(DashboardWidgetRegistry::definitions())
            ->filter(fn (array $definition): bool => $user->can($definition['permission'])
                && Module::isEnabled(explode('.', $definition['permission'])[0]))
            ->all());
        $selected = array_values(array_intersect($data['widgets'] ?? [], $allowed));

        DB::transaction(function () use ($user, $allowed, $selected): void {
            DashboardWidgetPreference::query()->where('user_id', $user->id)->delete();
            collect($allowed)->each(fn (string $key, int $index) => DashboardWidgetPreference::create([
                'user_id' => $user->id,
                'widget_key' => $key,
                'sort_order' => $index,
                'is_visible' => in_array($key, $selected, true),
            ]));
        });

        return redirect()->route('dashboard')->with('toasts', [['type' => 'success', 'message' => 'Your dashboard layout was updated.']]);
    }

    private function ensureCompanyRole(Role $role): void
    {
        abort_if($role->name === config('roles.super_admin'), 404);
    }

    /**
     * Super Admin personalises its own dashboard at /dashboard/customize and is
     * never managed by another role, so the per-role screens do not apply to it
     * at all. Without this the sidebar/tabs could still surface a link that
     * resolves to a role it may not edit.
     */
    private function ensureNotSuperAdmin(): void
    {
        abort_if(auth()->user()?->isSuperAdmin(), 404);
    }
}
