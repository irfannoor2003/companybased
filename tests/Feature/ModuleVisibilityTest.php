<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Support\NavBuilder;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * The product rule these tests enforce:
 *
 *   - a module ON  -> every role that holds its permissions can use it
 *   - a module OFF -> it is invisible to everybody, everywhere, including the
 *                     dashboard widgets and quick actions
 *
 * Attendance is the case that broke. The nav and the routes were both correctly
 * gated, but `DashboardService::quickActions()` filtered on permission alone, so
 * with Employees switched off a manager still got a "Mark attendance" shortcut
 * on the dashboard. A shortcut into a page that 403s is worse than no shortcut:
 * the feature was supposed to be invisible and was still on screen.
 */
class ModuleVisibilityTest extends TestCase
{
    use SeedsDatabase;

    private const STAFF_ROLES = ['Employee', 'Salesman', 'Inventory Manager', 'HR'];

    private function disable(string $key): void
    {
        Module::query()->where('key', $key)->update(['enabled' => false]);
        Module::flushCache();
    }

    private function makeTrackedEmployee(string $roleName): \App\Models\User
    {
        return $this->employeeUserForRole($roleName, [], [
            'employee_code' => 'EMP-'.strtoupper(str($roleName)->substr(0, 6)->value()).'-'.uniqid(),
        ]);
    }

    public function test_every_staff_role_can_mark_attendance_when_the_module_is_on(): void
    {
        foreach (self::STAFF_ROLES as $roleName) {
            $user = $this->makeTrackedEmployee($roleName);

            $this->assertTrue($user->isAttendanceTracked(), "{$roleName} must be attendance-tracked");

            $this->actingAs($user)
                ->get('/employees/my-attendance')
                ->assertOk()
                ->assertSee('Clock in');

            $nav = NavBuilder::items($user)->first(fn ($e) => ($e['item']['group'] ?? null) === 'Employees');

            $this->assertTrue($nav['visible'], "{$roleName}: the Employees group must stay visible");
            $this->assertContains('My Attendance', collect($nav['children'])->pluck('label')->all());
        }
    }

    public function test_no_staff_role_sees_attendance_when_the_module_is_off(): void
    {
        $this->disable('employees');

        foreach (self::STAFF_ROLES as $roleName) {
            $user = $this->makeTrackedEmployee($roleName);

            $nav = NavBuilder::items($user)->first(fn ($e) => ($e['item']['group'] ?? null) === 'Employees');

            $this->assertFalse(
                $nav['visible'] && collect($nav['children'])->pluck('label')->contains('My Attendance'),
                "{$roleName}: My Attendance must not survive in the nav when Employees is off",
            );

            $this->actingAs($user)->get('/employees/my-attendance')->assertForbidden();
        }
    }

    public function test_quick_actions_drop_a_disabled_module_for_a_user_who_still_holds_the_permission(): void
    {
        // Admin holds employees.attendance.mark (admin-side manual clock-in) but
        // has no employee profile, so the self-service test cannot use them.
        $admin = $this->userForRole('Admin');

        $this->assertTrue($admin->can('employees.attendance.mark'), 'precondition: Admin holds the permission');

        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Mark attendance');

        // Self-service permission, module-gated the same way, for a tracked user.
        $employee = $this->makeTrackedEmployee('Employee');

        $this->actingAs($employee)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Request leave');

        // Now switch the module off: both shortcuts must disappear, even though
        // both users still hold the underlying permissions.
        $this->disable('employees');

        $this->actingAs($admin->fresh())
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('Mark attendance')
            ->assertDontSee('/employees/attendance');

        $this->actingAs($employee->fresh())
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('Request leave')
            ->assertDontSee('/employees/leave/my/create');
    }

    public function test_the_my_attendance_widget_disappears_when_the_module_is_off(): void
    {
        $user = $this->makeTrackedEmployee('Employee');

        $this->disable('employees');

        $response = $this->actingAs($user->fresh())->get('/dashboard')->assertOk();

        $this->assertStringNotContainsString(
            '/employees/my-attendance',
            $response->getContent(),
            'no attendance link may render while Employees is off',
        );
    }

    public function test_a_disabled_module_names_itself_in_the_403(): void
    {
        $this->disable('employees');

        $this->actingAs($this->makeTrackedEmployee('Employee'))
            ->get('/employees/my-attendance')
            ->assertForbidden()
            ->assertSee('The &quot;Employees&quot; module is currently disabled', false);
    }

    public function test_visits_obeys_the_same_rule(): void
    {
        $this->disable('visits');

        $user = $this->makeTrackedEmployee('Salesman');

        $this->assertTrue(
            $user->can('visits.visits.view'),
            'precondition: Salesman holds the visits permission',
        );

        $nav = NavBuilder::items($user)->first(fn ($e) => ($e['item']['group'] ?? null) === 'Visits');

        $this->assertFalse($nav['visible'], 'the Visits group must vanish while the module is off');

        $this->actingAs($user)->get('/visits')->assertForbidden();
    }
}