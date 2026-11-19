<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\SidebarPreference;
use App\Models\User;
use App\Support\NavBuilder;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * Admins can choose the links their role (or they personally) see, including
 * custom links, without a deploy. The preference is only ever a visibility
 * filter: the module and permission gates still decide what a user may open.
 */
class SidebarCustomizationTest extends TestCase
{
    use SeedsDatabase;

    private function admin(): User
    {
        return User::where('email', 'admin@nexosdigital.test')->firstOrFail();
    }

    private function visibleKeys(?User $user = null): array
    {
        $user ??= $this->admin();

        return NavBuilder::items($user)
            ->filter(fn ($entry) => $entry['visible'])
            ->map(fn ($entry) => $entry['item']['route'] ?? ($entry['item']['group'] ?? null))
            ->filter()
            ->all();
    }

    public function test_a_role_preference_hides_a_link(): void
    {
        $role = Role::where('name', 'Admin')->firstOrFail();
        $this->assertContains('dashboard', $this->visibleKeys());

        SidebarPreference::create([
            'role_id' => $role->id,
            'nav_key' => 'dashboard',
            'is_visible' => false,
        ]);

        $this->assertNotContains('dashboard', $this->visibleKeys());
    }

    public function test_a_user_preference_overrides_the_role_for_that_key_only(): void
    {
        $role = Role::where('name', 'Admin')->firstOrFail();
        $user = $this->admin();

        SidebarPreference::create([
            'role_id' => $role->id, 'nav_key' => 'dashboard', 'is_visible' => false,
        ]);
        SidebarPreference::create([
            'role_id' => $role->id, 'nav_key' => 'sales.invoices.index', 'is_visible' => false,
        ]);
        SidebarPreference::create([
            'user_id' => $user->id, 'nav_key' => 'dashboard', 'is_visible' => true,
        ]);

        $keys = $this->visibleKeys($user);

        $this->assertContains('dashboard', $keys, 'the user override wins for this key');
        $this->assertNotContains('sales.invoices.index', $keys, 'the role default still applies to other keys');
    }

    public function test_a_preference_can_never_reveal_a_link_the_user_cannot_access(): void
    {
        $user = $this->userForRole('Salesman');

        SidebarPreference::create([
            'user_id' => $user->id,
            'nav_key' => 'settings.roles.index',
            'is_visible' => true,
        ]);

        $this->assertNotContains('settings.roles.index', $this->visibleKeys($user));
    }

    public function test_a_custom_link_is_appended_to_the_sidebar(): void
    {
        SidebarPreference::create([
            'user_id' => $this->admin()->id,
            'nav_key' => 'custom:1',
            'label' => 'Grand report',
            'url' => '/reports/grand',
            'icon' => 'report',
            'is_visible' => true,
        ]);

        $section = NavBuilder::items($this->admin())
            ->first(fn ($entry) => ($entry['item']['group'] ?? null) === 'Custom Links');

        $this->assertNotNull($section, 'custom links must appear in their own labelled section');
        $this->assertTrue($section['visible']);
        $this->assertCount(1, $section['children']);
        $this->assertSame('Grand report', $section['children']->first()['label']);
        $this->assertSame('/reports/grand', $section['children']->first()['url']);
    }

    public function test_the_personal_editor_renders_and_saves_choices(): void
    {
        $this->actingAs($this->admin())
            ->get(route('sidebar.customize'))
            ->assertOk()
            ->assertSee('name="visible[]"', false)
            ->assertSee('catalog.products.index', false);

        $this->actingAs($this->admin())
            ->put(route('sidebar.customize.update'), [
                'visible' => ['dashboard', 'catalog.products.index'],
                'custom' => [
                    ['label' => 'My link', 'url' => '/reports/grand', 'icon' => 'report'],
                ],
            ])
            ->assertRedirect(route('sidebar.customize'));

        $saved = SidebarPreference::where('user_id', $this->admin()->id)->get()->keyBy('nav_key');
        $this->assertTrue($saved->get('dashboard')->is_visible);
        $this->assertTrue($saved->get('catalog.products.index')->is_visible);
        $this->assertFalse($saved->get('catalog.brands.index')->is_visible, 'an unticked link is stored as hidden');
        $this->assertSame('/reports/grand', $saved->get('custom:1')->url);
    }

    public function test_unknown_keys_and_unsafe_urls_are_refused(): void
    {
        $this->actingAs($this->admin())
            ->put(route('sidebar.customize.update'), [
                'visible' => ['not.a.real.route'],
                'custom' => [
                    ['label' => 'Bad', 'url' => 'javascript:alert(1)', 'icon' => 'report'],
                ],
            ])
            ->assertRedirect(route('sidebar.customize'));

        $saved = SidebarPreference::where('user_id', $this->admin()->id)->get();
        $this->assertTrue($saved->doesntContain('nav_key', 'not.a.real.route'));
        $this->assertSame(0, $saved->filter(fn ($p) => $p->isCustom())->count());
    }

    public function test_reset_clears_personal_choices(): void
    {
        SidebarPreference::create([
            'user_id' => $this->admin()->id, 'nav_key' => 'dashboard', 'is_visible' => false,
        ]);

        $this->actingAs($this->admin())
            ->delete(route('sidebar.customize.reset'))
            ->assertRedirect(route('sidebar.customize'));

        $this->assertSame(0, SidebarPreference::where('user_id', $this->admin()->id)->count());
    }

    /**
     * A link the role cannot reach must not even be offered as a toggle.
     * Admin deliberately lacks settings.modules.* (Super Admin only), so the
     * Modules entry must be absent from its editor rather than a checkbox that
     * can never have any effect.
     */
    public function test_the_role_editor_only_lists_links_that_role_can_reach(): void
    {
        $role = Role::where('name', 'Admin')->firstOrFail();

        $this->assertFalse($role->permissions->contains('name', 'settings.modules.view'));

        $html = $this->actingAs($this->admin())
            ->get(route('settings.sidebar.edit', $role))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('value="settings.modules"', $html);
        $this->assertStringContainsString('value="settings.templates.index"', $html, 'links the role does have are still offered');
    }

    public function test_a_disabled_module_is_not_offered_even_with_the_permission(): void
    {
        \App\Models\Module::where('key', 'catalog')->update(['enabled' => false]);
        \App\Models\Module::flushCache();

        $html = $this->actingAs($this->admin())
            ->get(route('sidebar.customize'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('value="catalog.products.index"', $html);
    }

    public function test_only_an_admin_can_edit_a_roles_defaults(): void
    {
        $role = Role::where('name', 'Salesman')->firstOrFail();
        $this->actingAs($this->userForRole('Salesman'))
            ->get(route('settings.sidebar.edit', $role))
            ->assertForbidden();

        $this->actingAs($this->admin())
            ->get(route('settings.sidebar.edit', $role))
            ->assertOk()
            ->assertSee('Sidebar — Salesman', false);
    }
}
