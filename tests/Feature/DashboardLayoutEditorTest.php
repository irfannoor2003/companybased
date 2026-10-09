<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * The per-role dashboard layout editor.
 *
 * The form posted to a route registered with Route::put() but omitted
 * `@method('PUT')`, so submitting it produced
 * "405 Method Not Allowed" for admins managing a role's dashboard.
 */
class DashboardLayoutEditorTest extends TestCase
{
    use SeedsDatabase;

    private function admin(): User
    {
        return $this->userForRole('Admin');
    }

    private function editableRole(): Role
    {
        return Role::where('name', 'Employee')->firstOrFail();
    }

    public function test_the_layout_editor_page_renders(): void
    {
        $response = $this->actingAs($this->admin())
            ->get(route('settings.dashboards.edit', $this->editableRole()));

        $response->assertOk();
    }

    /**
     * Regression: the form must spoof PUT. Without @method('PUT') the browser
     * sent a plain POST to a PUT-only route.
     */
    public function test_the_layout_form_spoofs_the_put_method(): void
    {
        $role = $this->editableRole();

        $response = $this->actingAs($this->admin())
            ->get(route('settings.dashboards.edit', $role));

        $response->assertOk();

        $html = $response->getContent();

        $this->assertStringContainsString(
            'name="_method"',
            $html,
            'the layout form must include @method(\'PUT\') or it 405s on submit',
        );
        $this->assertStringContainsString('value="PUT"', $html);
        $this->assertStringContainsString(
            route('settings.dashboards.update', $role),
            $html,
            'the form must post to the PUT route',
        );
    }

    public function test_an_admin_can_save_a_role_layout(): void
    {
        $role = $this->editableRole();

        $this->actingAs($this->admin())
            ->put(route('settings.dashboards.update', $role), [
                'widgets' => ['my_attendance', 'my_leave'],
            ])
            ->assertSessionHasNoErrors();

        $stored = $role->dashboardWidgets()->pluck('widget_key')->all();

        $this->assertContains('my_attendance', $stored);
        $this->assertContains('my_leave', $stored);
    }

    /**
     * A plain POST — the browser's default, which is what produced the 405.
     */
    public function test_posting_the_layout_form_directly_does_not_return_405(): void
    {
        $role = $this->editableRole();

        $response = $this->actingAs($this->admin())
            ->post(route('settings.dashboards.update', $role), [
                '_method' => 'PUT',
                'widgets' => ['my_attendance'],
            ]);

        $this->assertNotSame(405, $response->getStatusCode());
        $response->assertSessionHasNoErrors();

        $this->assertContains('my_attendance', $role->dashboardWidgets()->pluck('widget_key')->all());
    }

    /**
     * Widgets the role is not offered must never be stored, whatever is posted.
     */
    public function test_widgets_outside_the_role_offer_are_rejected(): void
    {
        $role = $this->editableRole();

        // `attendance_today` is not offered to Employee, so it must be dropped.
        $this->actingAs($this->admin())
            ->put(route('settings.dashboards.update', $role), [
                'widgets' => ['my_attendance', 'attendance_today'],
            ])
            ->assertSessionHasNoErrors();

        $stored = $role->dashboardWidgets()->pluck('widget_key')->all();

        $this->assertContains('my_attendance', $stored);
        $this->assertNotContains('attendance_today', $stored, 'a widget outside the role offer must not be stored');
    }

    public function test_super_admin_cannot_edit_role_dashboards(): void
    {
        $role = $this->editableRole();

        $this->actingAs($this->userForRole('Super Admin'))
            ->get(route('settings.dashboards.edit', $role))
            ->assertNotFound();
    }
}
