<?php

namespace Tests\Feature;

use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * Super Admin and Admin are back-office accounts, not members of staff. They
 * must never be able to mark attendance or request leave, because attendance
 * feeds payroll and a phantom employee would corrupt it.
 */
class EmployeeOnlyAttendanceTest extends TestCase
{
    use SeedsDatabase;

    public function test_super_admin_is_not_attendance_tracked(): void
    {
        $user = $this->userForRole('Super Admin');

        $this->assertTrue($user->isSuperAdmin(), 'precondition: user has the Super Admin role');
        $this->assertTrue($user->isAdmin());
        $this->assertFalse($user->isAttendanceTracked());
        $this->assertNull($user->employeeProfile(), 'precondition: Super Admin has no employee profile');
    }

    public function test_admin_is_not_attendance_tracked(): void
    {
        $user = $this->userForRole('Admin');

        $this->assertTrue($user->isAdmin());
        $this->assertFalse($user->isAttendanceTracked());
        $this->assertNull($user->employeeProfile(), 'precondition: Admin has no employee profile');
    }

    public function test_admin_roles_are_not_granted_self_service_attendance_permissions(): void
    {
        foreach (['Super Admin', 'Admin'] as $roleName) {
            $user = $this->userForRole($roleName);

            $this->assertFalse(
                $user->can('employees.my_attendance.view'),
                "{$roleName} must not hold employees.my_attendance.view",
            );
            $this->assertFalse(
                $user->can('employees.my_attendance.mark'),
                "{$roleName} must not hold employees.my_attendance.mark",
            );
            $this->assertFalse(
                $user->can('employees.my_leave.view'),
                "{$roleName} must not hold employees.my_leave.view",
            );
        }
    }

    public function test_admin_and_super_admin_are_forbidden_from_attendance_pages(): void
    {
        foreach (['Super Admin', 'Admin'] as $roleName) {
            $user = $this->userForRole($roleName);

            $this->actingAs($user)
                ->get('/employees/my-attendance')
                ->assertForbidden();

            $this->actingAs($user)
                ->get('/employees/my-leave')
                ->assertForbidden();
        }
    }

    public function test_employee_middleware_blocks_a_permission_granting_non_employee(): void
    {
        // Defence in depth: even if someone re-grants the permission in the role
        // matrix, a login without an employee profile must not reach attendance.
        $user = $this->userForRole('Admin');
        $user->givePermissionTo('employees.my_attendance.view');

        $this->assertTrue($user->fresh()->can('employees.my_attendance.view'));

        $this->actingAs($user->fresh())
            ->get('/employees/my-attendance')
            ->assertForbidden();
    }

    public function test_real_employee_can_still_use_attendance(): void
    {
        $user = $this->employeeUserForRole('Employee');

        $this->assertTrue($user->isAttendanceTracked());
        $this->actingAs($user)->get('/employees/my-attendance')->assertOk();
        $this->actingAs($user)->get('/employees/my-leave')->assertOk();
    }

    public function test_employee_with_attendance_disabled_is_not_tracked(): void
    {
        $user = $this->employeeUserForRole('Employee', [], ['attendance_enabled' => false]);

        $this->assertFalse($user->isAttendanceTracked());
        $this->actingAs($user)->get('/employees/my-attendance')->assertForbidden();
    }

    public function test_inactive_employee_is_not_tracked(): void
    {
        $user = $this->employeeUserForRole('Employee', [], ['employment_status' => 'resigned']);

        $this->assertFalse($user->isAttendanceTracked());
        $this->actingAs($user)->get('/employees/my-attendance')->assertForbidden();
    }

    public function test_deactivated_user_cannot_use_attendance(): void
    {
        $user = $this->employeeUserForRole('Employee');

        $user->update(['is_active' => false]);

        $this->actingAs($user->fresh())->get('/employees/my-attendance')->assertRedirect();
    }
}
