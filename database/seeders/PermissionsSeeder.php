<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Support\Permissions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\PermissionRegistrar;

class PermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $registered = Permissions::all();

        // Keep anything already registered, purge stale permissions.
        $existing = Permission::pluck('name')->all();

        $missing = array_values(array_diff($registered, $existing));

        if ($missing !== []) {
            // One INSERT instead of ~355. The per-row create() also fired the
            // Auditable trait, writing an audit_logs row for every permission,
            // which roughly doubled the cost of seeding.
            $now = now();

            Permission::insert(array_map(fn (string $name): array => [
                'name' => $name,
                'guard_name' => 'web',
                'created_at' => $now,
                'updated_at' => $now,
            ], $missing));
        }

        Permission::whereNotIn('name', $registered)->delete();

        // The Super Admin always manages packages; grant without revoking anything else.
        if ($superAdmin = Role::where('name', 'Super Admin')->where('guard_name', 'web')->first()) {
            $superAdmin->givePermissionTo(['settings.subscription.view', 'settings.subscription.manage']);
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Cache::forget('permissions.registry.keys');
    }
}
