<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Role;
use App\Models\Visit;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * Role names used to be string literals in ~25 places. Because most checks read
 * "does this user have role X", renaming a role in the seeder made the check
 * return false everywhere else — and where the check gates an ownership rule
 * (a Salesman may only manage their own visits) a false result *removes* the
 * restriction instead of denying access.
 *
 * These tests pin the config-backed helpers and the ownership scoping they
 * drive.
 */
class RoleScopingTest extends TestCase
{
    use SeedsDatabase;

    public function test_every_configured_role_exists_after_seeding(): void
    {
        foreach ([
            'super_admin', 'admin', 'hr', 'accountant',
            'salesman', 'inventory_manager', 'employee',
        ] as $key) {
            $this->assertNotNull(
                Role::where('name', config("roles.{$key}"))->first(),
                "the configured {$key} role must be seeded",
            );
        }
    }

    public function test_the_staff_list_matches_the_seeded_employee_profiles(): void
    {
        $staff = config('roles.staff');

        foreach ($staff as $roleName) {
            $this->assertGreaterThan(
                0,
                Employee::whereHas('user.roles', fn ($q) => $q->where('name', $roleName))->count(),
                "the {$roleName} role must have at least one employee profile",
            );
        }

        // Back-office accounts are deliberately not staff.
        foreach (['admin', 'super_admin', 'accountant'] as $key) {
            $this->assertSame(
                0,
                Employee::whereHas('user.roles', fn ($q) => $q->where('name', config("roles.{$key}")))->count(),
                'back-office roles must not have employee profiles',
            );
        }
    }

    public function test_is_admin_and_is_super_admin(): void
    {
        $admin = $this->userForRole('Admin');
        $salesman = $this->userForRole('Salesman');

        $this->assertTrue($admin->isAdmin());
        $this->assertFalse($admin->isSuperAdmin());
        $this->assertFalse($salesman->isAdmin());
    }

    public function test_is_salesman_excludes_admin_roles(): void
    {
        $salesman = $this->userForRole('Salesman');
        $admin = $this->userForRole('Admin');

        $this->assertTrue($salesman->isSalesman());
        $this->assertFalse($admin->isSalesman(), 'admins supervise rather than being scoped to their own records');
    }

    public function test_is_hr_manager(): void
    {
        $this->assertTrue($this->userForRole('HR')->isHrManager());
        $this->assertTrue($this->userForRole('Admin')->isHrManager());
        $this->assertFalse($this->userForRole('Salesman')->isHrManager());
    }

    public function test_a_salesman_cannot_open_another_reps_visit(): void
    {
        $salesman = $this->userForRole('Salesman');
        $otherRep = Employee::whereHas('user.roles', fn ($q) => $q->where('name', config('roles.inventory_manager')))->firstOrFail();

        $visit = Visit::create([
            'visit_number' => 'V-OTHER',
            'sales_rep_id' => $otherRep->id,
            'status' => 'pending',
            'scheduled_at' => now()->toDateString(),
        ]);

        $this->actingAs($salesman)
            ->get("/visits/{$visit->id}")
            ->assertForbidden();
    }

    public function test_a_salesman_can_open_their_own_visit(): void
    {
        $salesman = $this->userForRole('Salesman');
        $employeeId = Employee::where('user_id', $salesman->id)->value('id');

        $visit = Visit::create([
            'visit_number' => 'V-OWN',
            'sales_rep_id' => $employeeId,
            'status' => 'pending',
            'scheduled_at' => now()->toDateString(),
        ]);

        $this->actingAs($salesman)
            ->get("/visits/{$visit->id}")
            ->assertOk();
    }
}
