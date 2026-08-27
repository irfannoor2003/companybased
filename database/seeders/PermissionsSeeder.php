<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Support\Permissions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

class PermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $registered = Permissions::all();

        // Keep anything already registered, purge stale permissions.
        $existing = Permission::pluck('name')->all();

        foreach (array_diff($registered, $existing) as $name) {
            Permission::create(['name' => $name]);
        }

        Permission::whereNotIn('name', $registered)->delete();

        // The Super Admin always manages packages; grant without revoking anything else.
        if ($superAdmin = Role::where('name', 'Super Admin')->where('guard_name', 'web')->first()) {
            $superAdmin->givePermissionTo(['settings.subscription.view', 'settings.subscription.manage']);
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        Cache::forget('permissions.registry.keys');
    }
}
