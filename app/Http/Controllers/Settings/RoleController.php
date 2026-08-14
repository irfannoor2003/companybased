<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RoleController extends Controller
{
    public function index(): View
    {
        $roles = Role::query()
            ->where('name', '!=', 'Super Admin')
            ->withCount(['users', 'permissions'])
            ->orderBy('name')
            ->get();

        return view('settings.roles.index', compact('roles'));
    }

    /**
     * Modules whose permissions are reserved for Super Admin only
     * and must never appear in the role-creation matrix.
     */
    protected const SUPER_ADMIN_MODULES = ['settings'];

    public function create(): View
    {
        $groups = collect(Permissions::grouped())
            ->except(static::SUPER_ADMIN_MODULES)
            ->all();

        return view('settings.roles.create', compact('groups'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('roles', 'name')],
            'label' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string'],
        ]);

        $role = Role::create([
            'name' => $data['name'],
            'label' => $data['label'] ?: $data['name'],
            'description' => $data['description'] ?? null,
            'guard_name' => 'web',
            'is_system' => false,
        ]);

        $role->syncPermissions($this->enabledModulePermissions($data['permissions'] ?? []));

        $this->auditPermissionChange($role, [], $role->permissions()->pluck('name')->all(), 'created');

        return redirect()->route('settings.roles.index')
            ->with('toasts', [['type' => 'success', 'message' => "Role \"{$role->name}\" created."]]);
    }

    public function edit(Role $role): View
    {
        if ($role->name === 'Super Admin') {
            abort(403, 'The Super Admin role owns every permission and cannot be edited.');
        }

        $groups = collect(Permissions::grouped())
            ->except(static::SUPER_ADMIN_MODULES)
            ->all();
        $current = $role->permissions()->pluck('name')->all();

        return view('settings.roles.edit', compact('role', 'groups', 'current'));
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('roles', 'name')->ignore($role->id)],
            'label' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string'],
        ]);

        $before = $role->permissions()->pluck('name')->all();

        $role->update([
            'name' => $data['name'],
            'label' => $data['label'] ?: $data['name'],
            'description' => $data['description'] ?? null,
        ]);

        $role->syncPermissions($this->enabledModulePermissions($data['permissions'] ?? []));

        $after = $role->permissions()->pluck('name')->all();
        $this->auditPermissionChange($role, $before, $after);

        return back()->with('toasts', [['type' => 'success', 'message' => "Role \"{$role->name}\" updated."]]);
    }

    public function destroy(Role $role): RedirectResponse
    {
        if ($role->is_system) {
            return back()->with('toasts', [['type' => 'error', 'message' => 'System roles cannot be deleted.']]);
        }

        if ($role->users()->exists()) {
            return back()->with('toasts', [['type' => 'error', 'message' => 'Role is assigned to users and cannot be deleted.']]);
        }

        $name = $role->name;
        $role->delete();

        return back()->with('toasts', [['type' => 'success', 'message' => "Role \"{$name}\" deleted."]]);
    }

    /**
     * Record an auditable trail whenever a role's permission set changes,
     * capturing who made the change and the previous / resulting permission set.
     */
    protected function auditPermissionChange(Role $role, array $before, array $after, string $event = 'updated'): void
    {
        $added = array_values(array_diff($after, $before));
        $removed = array_values(array_diff($before, $after));

        AuditLog::create([
            'user_id' => auth()->id(),
            'module' => 'settings',
            'event' => $event,
            'auditable_type' => $role->getMorphClass(),
            'auditable_id' => $role->getKey(),
            'description' => "Permissions for role \"{$role->name}\" updated"
                .(count($added) ? ' (added: '.implode(', ', $added).')' : '')
                .(count($removed) ? ' (removed: '.implode(', ', $removed).')' : ''),
            'old_values' => $before ?: null,
            'new_values' => $after ?: null,
            'ip_address' => request()->ip(),
            'user_agent' => substr((string) request()->userAgent(), 0, 500),
        ]);
    }

    /**
     * Defense-in-depth: keep only permission keys that belong to modules
     * the Super Admin has enabled for this company. Prevents a crafted POST
     * from granting a role permissions against a disabled module.
     */
    protected function enabledModulePermissions(array $permissions): array
    {
        $enabled = Module::enabledKeys();

        return array_values(array_filter($permissions, function (string $permission) use ($enabled) {
            $module = explode('.', $permission)[0];

            return in_array($module, $enabled, true)
                && ! in_array($module, static::SUPER_ADMIN_MODULES, true);
        }));
    }
}
