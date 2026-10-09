<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\Subscription;
use App\Models\User;
use App\Support\NavBuilder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Pins the sidebar visibility rule that used to live in a 59-line `@php` block.
 *
 * The block was not directly testable without rendering a full page, and it was a
 * separate implementation of the same decision the route middleware enforces. These
 * tests exist so extracting it into NavBuilder cannot silently change which links
 * a user is offered.
 */
class NavBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // CheckSubscription returns a 403 for any non-Super-Admin when no package
        // is active, which would make every authenticated request below fail before
        // the route being tested was ever reached. Same trap as CRITICAL-1.
        Subscription::create([
            'plan_name' => 'Test Plan',
            'starts_at' => now()->subMonth(),
            'expires_at' => now()->addYear(),
            'is_active' => true,
        ]);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@nexosdigital.test')->firstOrFail();
    }

    /**
     * The registry itself must be coherent: every entry either routes somewhere or
     * is a group of entries that do. A typo'd route name throws at render time and
     * takes the whole layout down, so it is worth asserting before that happens.
     */
    public function test_every_navigable_entry_resolves_to_a_registered_route(): void
    {
        $routes = collect(\Illuminate\Support\Facades\Route::getRoutes())->map->getName()->filter()->all();

        foreach (config('nav.items', []) as $entry) {
            if (! empty($entry['group'])) {
                foreach ($entry['items'] ?? [] as $child) {
                    $this->assertContains($child['route'], $routes, "Nav entry [{$child['route']}] has no registered route.");
                }

                continue;
            }

            $this->assertContains($entry['route'], $routes, "Nav entry [{$entry['route']}] has no registered route.");
        }
    }

    /**
     * The census already fails the build on unknown permission strings; this asserts
     * the same invariant for the nav registry specifically, because a bad string here
     * withholds a link without any error.
     */
    public function test_every_nav_permission_exists_in_the_registry(): void
    {
        $permissions = collect(\Spatie\Permission\Models\Permission::query()->get())
            ->map(fn ($p) => $p->name)
            ->all();

        $check = function (array $entry) use ($permissions, &$check): void {
            if (! empty($entry['group'])) {
                foreach ($entry['items'] ?? [] as $child) {
                    $check($child);
                }

                return;
            }

            if (! empty($entry['permission'])) {
                $this->assertContains($entry['permission'], $permissions, "Nav permission [{$entry['permission']}] is not registered.");
            }
        };

        foreach (config('nav.items', []) as $entry) {
            $check($entry);
        }
    }

    public function test_admin_sees_entries_their_permissions_allow(): void
    {
        $visible = NavBuilder::items($this->admin())
            ->filter(fn ($nav) => $nav['visible'])
            ->map(fn ($nav) => $nav['item']['route'] ?? $nav['item']['group']);

        $this->assertContains('dashboard', $visible->all());
    }

    /**
     * A disabled module hides its whole group, and a group whose children are all
     * withheld disappears entirely rather than rendering as an empty accordion.
     */
    public function test_a_group_with_no_visible_children_is_hidden(): void
    {
        config(['nav.items' => [
            [
                'group' => 'Locked',
                'module' => 'sales',
                'items' => [
                    ['label' => 'Nothing', 'route' => 'sales.quotes.index', 'icon' => 'document', 'permission' => 'a.permission.nobody.holds'],
                ],
            ],
        ]]);

        $items = NavBuilder::items($this->admin(), ['dashboard', 'catalog']);

        $this->assertFalse($items->first()['visible']);
        $this->assertTrue($items->first()['children']->isEmpty());
    }

    public function test_an_entry_whose_module_is_disabled_is_hidden(): void
    {
        config(['nav.items' => [
            ['label' => 'Reports', 'route' => 'reports.index', 'icon' => 'reports', 'module' => 'reports', 'permission' => 'reports.reports.view'],
        ]]);

        $this->assertFalse(NavBuilder::items($this->admin(), ['dashboard'])->first()['visible']);
    }

    public function test_an_entry_with_no_module_key_is_always_module_enabled(): void
    {
        config(['nav.items' => [
            ['label' => 'Profile', 'route' => 'profile.edit', 'icon' => 'user'],
        ]]);

        $this->assertTrue(NavBuilder::items($this->admin(), [])->first()['visible']);
    }

    /**
     * `employee_only` gates the self-service HR screens on having a real employee
     * profile; a user without one has nothing to show there.
     */
    public function test_employee_only_entries_hide_for_a_user_with_no_employee_profile(): void
    {
        config(['nav.items' => [
            ['label' => 'My Attendance', 'route' => 'employees.my.attendance', 'icon' => 'clock', 'employee_only' => true],
        ]]);

        $user = $this->admin();
        $this->assertFalse((bool) $user->isAttendanceTracked());
        $this->assertFalse(NavBuilder::items($user)->first()['visible']);
    }

    /**
     * An `.index` name stands for its section, so the highlight must survive
     * navigating into a record beneath it.
     */
    /**
     * `catalog.products.create` sits under `catalog.products.index`, so the section
     * entry must stay highlighted while the user is on a screen beneath it —
     * otherwise opening the create form drops the highlight and the user loses their
     * place in the tree.
     */
    public function test_an_index_route_is_active_for_the_whole_section(): void
    {
        $this->actingAs($this->admin());

        $this->get(route('catalog.products.create'))->assertOk();

        $this->assertSame('catalog.products.create', request()->route()->getName());
        $this->assertTrue(NavBuilder::isActive('catalog.products.index'));
        $this->assertFalse(NavBuilder::isActive('sales.quotes.index'));
    }

    public function test_a_route_is_active_for_its_own_name_and_its_children(): void
    {
        $this->actingAs($this->admin());

        $this->get(route('catalog.products.create'))->assertOk();

        // Its own name...
        $this->assertTrue(NavBuilder::isActive('catalog.products.create'));

        // ...and the parent section it lives under, by prefix match.
        $this->assertTrue(NavBuilder::isActive('catalog.products'));

        // An unrelated section must not light up.
        $this->assertFalse(NavBuilder::isActive('sales.quotes.index'));
    }

    public function test_is_active_is_false_for_a_blank_route_name(): void
    {
        $this->actingAs($this->admin());

        $this->get(route('catalog.products.create'))->assertOk();

        // A nav entry with no route must not blank-highlight whatever is current.
        $this->assertFalse(NavBuilder::isActive(''));
        $this->assertFalse(NavBuilder::isActive(null));
    }

    public function test_module_enabled_keys_are_the_source_of_truth(): void
    {
        // Guards the assumption NavBuilder::items() defaults on.
        $this->assertIsArray(Module::enabledKeys());
    }
}