<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Tests\SeedsDatabase;
use Tests\TestCase;

class RolePermissionSyncTest extends TestCase
{
    use SeedsDatabase;

    public function test_role_update_registers_a_configured_permission_missing_from_the_database(): void
    {
        $admin = $this->userForRole('Admin');
        $role = Role::where('name', 'Admin')->firstOrFail();
        $permission = Permission::where('name', 'accounting.expense_claims.self_approve')->firstOrFail();

        DB::table(config('permission.table_names.role_has_permissions'))
            ->where('permission_id', $permission->id)
            ->delete();
        $permission->delete();

        $this->actingAs($admin)
            ->put(route('settings.roles.update', $role), [
                'name' => $role->name,
                'label' => $role->label,
                'description' => $role->description,
                'permissions' => [
                    'accounting.expense_claims.approve',
                    'accounting.expense_claims.self_approve',
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('permissions', [
            'name' => 'accounting.expense_claims.self_approve',
            'guard_name' => 'web',
        ]);
        $this->assertTrue($role->fresh()->hasPermissionTo('accounting.expense_claims.self_approve'));
    }
}
