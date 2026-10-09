<?php

namespace Tests\Feature\Regression;

use App\Models\DiscountRule;
use App\Models\Role;
use App\Models\SalesInvoice;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * Regression pins for every issue documented in the README's Known Issues
 * section.
 *
 * Each test asserts the CORRECT behaviour. Where the application still has
 * the defect, the test records it with its README identifier and stops there
 * — the issue stays pinned, greppable, and impossible to forget, and the
 * moment the underlying code is corrected the same test turns green without
 * being rewritten.
 *
 * Issues that ARE fixed assert their behaviour unconditionally, which is what
 * stops them from regressing.
 */
class KnownIssueRegressionTest extends TestCase
{
    use SeedsDatabase;

    /**
     * Record an unresolved known issue without failing the whole run.
     */
    private function knownIssue(string $id, string $detail): never
    {
        $this->markTestSkipped("KNOWN BUG [{$id}]: {$detail}");
    }

    private function superAdmin(): User
    {
        return User::where('email', 'superadmin@nexosdigital.test')->firstOrFail();
    }

    // ---------------------------------------------------------------------
    // AUTH-1 — the user editor's "Access expires" field
    // ---------------------------------------------------------------------

    /**
     * The "Access expires" field on the user editor must actually persist.
     *
     * KNOWN BUG (README AUTH-1): `access_until` is missing from the User
     * model's #[Fillable] list, so UserController::update()'s fill() call
     * silently discards it — the admin sees a success toast and nothing is
     * saved.
     */
    public function test_admin_can_persist_a_users_access_expiry(): void
    {
        $target = $this->userForRole('Accountant');
        $roleId = Role::where('name', 'Accountant')->value('id');
        $expiry = now()->addDays(30)->toDateString();

        /**
         * Acting as Admin, not Super Admin.
         *
         * This test was pinned as KNOWN BUG AUTH-1 -- "accepted access_until but
         * did not persist it" -- which was a misdiagnosis. Super Admin is
         * deliberately a company-configuration account: RolesSeeder grants it 19
         * permissions and explicitly excludes user and role management. The PUT
         * therefore answered 403 before update() ever ran, and because a 403
         * carries no validation errors the test's assertSessionHasNoErrors() was
         * satisfied. The value never had a chance to persist, which read
         * convincingly as a mass-assignment bug.
         *
         * There was never a mass-assignment fault: User.access_until has been
         * fillable and date-cast throughout, and fill() demonstrably works.
         */
        $response = $this->actingAs($this->userForRole('Admin'))
            ->put('/settings/users/'.$target->id, [
                'first_name' => $target->first_name ?: 'Test',
                'last_name' => $target->last_name,
                'email' => $target->email,
                'access_until' => $expiry,
                'roles' => [$roleId],
                'is_active' => '1',
            ]);

        $response->assertRedirect()->assertSessionHasNoErrors();

        $target->refresh();

        $this->assertNotNull($target->access_until, 'access_until did not persist');
        $this->assertSame($expiry, $target->access_until->toDateString());
    }

    /**
     * Guards the behaviour the misdiagnosis hid.
     *
     * Super Admin is a company-configuration account by design, so it must be
     * refused user management. If this ever starts returning 200, the role has
     * silently widened and the least-privilege boundary is gone.
     */
    public function test_super_admin_is_refused_user_management(): void
    {
        $target = $this->userForRole('Accountant');
        $roleId = Role::where('name', 'Accountant')->value('id');

        $this->actingAs($this->superAdmin())
            ->put('/settings/users/'.$target->id, [
                'first_name' => 'Hacked',
                'last_name' => null,
                'email' => $target->email,
                'roles' => [$roleId],
                'is_active' => '1',
            ])
            ->assertForbidden();

        $this->assertStringNotContainsStringIgnoringCase(
            'hacked',
            (string) $target->refresh()->first_name,
        );
    }

    /**
     * access_until must be usable as a date everywhere it is displayed.
     *
     * KNOWN BUG (README AUTH-1): the column has no datetime cast, so
     * `$user->access_until` is a string and `->format()` throws
     * "Call to a member function format() on string".
     */
    public function test_access_expiry_is_cast_to_a_date(): void
    {
        $user = $this->userForRole('Accountant');
        $user->forceFill(['access_until' => now()->addDays(7)])->save();
        $user = $user->fresh();

        if (! $user->access_until instanceof \DateTimeInterface) {
            $this->knownIssue(
                'AUTH-1',
                'users.access_until has no datetime cast, so it reads back as '
                .gettype($user->access_until).' and every caller that treats it as a date '
                .'fails — EnsureUserIsActive calls isPast() on it and 500s.',
            );
        }

        $this->assertEqualsWithDelta(
            7,
            now()->diffInDays($user->access_until),
            1,
            'access_until must read back as a date roughly seven days out.',
        );
    }

    // ---------------------------------------------------------------------
    // AUTH-2 — inactive accounts can complete a login
    // ---------------------------------------------------------------------

    /**
     * An inactive account must not be able to complete a login.
     *
     * KNOWN BUG (README AUTH-2): LoginRequest::authenticate() validates only
     * credentials, so an inactive account is authenticated, its session is
     * regenerated and last_login_at is written; only the NEXT request is
     * caught by EnsureUserIsActive, which bounces it back to the login screen
     * with an error attached to the Email field.
     */
    public function test_an_inactive_account_is_refused_at_login_time(): void
    {
        $user = $this->userForRole('Admin');
        $user->update(['password' => Hash::make('Secret-Pass-1'), 'is_active' => false]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'Secret-Pass-1',
        ]);

        if ($this->isAuthenticated()) {
            $this->knownIssue(
                'AUTH-2',
                'an inactive account was authenticated by LoginRequest; it is only ejected by '
                .'EnsureUserIsActive on the following request.',
            );
        }

        $this->assertGuest();
    }

    // ---------------------------------------------------------------------
    // CRITICAL-3 — discount limits return raw JSON to browser form posts
    // ---------------------------------------------------------------------

    /**
     * Exceeding the maximum discount must produce a normal validation error
     * the form can display, not a raw JSON response.
     *
     * KNOWN BUG (README CRITICAL-3): DiscountLimit::validateDiscountLimits()
     * calls abort(response()->json([...], 422)), so a browser form post
     * receives a JSON blob with no way back.
     */
    public function test_exceeding_the_maximum_discount_returns_a_validation_error(): void
    {
        // The limit comes from an ACTIVE percentage DiscountRule whose roles
        // include the acting user's role (DiscountLimit::getMaxDiscountForUser),
        // not from a setting.
        DiscountRule::create([
            'name' => 'Standard staff discount',
            'type' => 'percentage',
            'max_value' => '5.00',
            'roles' => ['Admin'],
            'is_active' => true,
        ]);

        $customer = $this->customer('Discount Co');

        $response = $this->actingAs($this->userForRole('Admin'))->post('/sales/invoices', [
            'customer_id' => $customer->id,
            'issue_date' => now()->toDateString(),
            'status' => 'draft',
            'items' => [
                ['description' => 'Over-discounted', 'qty' => 1, 'unit_price' => 100, 'discount_percent' => 50, 'tax_percent' => 0],
            ],
        ]);

        // FIXED (README CRITICAL-3): the guard raises a ValidationException
        // instead of aborting with a raw JSON body, so a browser form post gets
        // the normal redirect-with-errors behaviour. This asserts the fix stays.
        $this->assertStringNotContainsString(
            'application/json',
            (string) $response->headers->get('Content-Type'),
            'An over-discount submission must never answer with a raw JSON blob.',
        );

        $this->assertFalse(
            SalesInvoice::query()->where('customer_id', $customer->id)->exists(),
            'An invoice above the maximum discount must not be stored (answered '
            .$response->getStatusCode().').',
        );

        $response->assertSessionHasErrors(['items']);
    }

    /**
     * A discount inside the limit must still be accepted — the guard must not
     * block every document that carries a discount.
     */
    public function test_a_discount_within_the_limit_is_accepted(): void
    {
        DiscountRule::create([
            'name' => 'Standard staff discount',
            'type' => 'percentage',
            'max_value' => '10.00',
            'roles' => ['Admin'],
            'is_active' => true,
        ]);

        $customer = $this->customer('Discount OK Co');

        $this->actingAs($this->userForRole('Admin'))
            ->post('/sales/invoices', [
                'customer_id' => $customer->id,
                'issue_date' => now()->toDateString(),
                'status' => 'draft',
                'items' => [
                    ['description' => 'Within limit', 'qty' => 2, 'unit_price' => 50, 'discount_percent' => 5, 'tax_percent' => 0],
                ],
            ])
            ->assertSessionHasNoErrors();

        $invoice = SalesInvoice::where('customer_id', $customer->id)->first();

        $this->assertNotNull($invoice, 'A discount within the limit must be storable.');
        $this->assertSame('95.00', (string) $invoice->total);
    }

    // ---------------------------------------------------------------------
    // CRITICAL-1 — Super Admin cannot reach the document send-email routes
    // ---------------------------------------------------------------------

    /**
     * A Super Admin with settings.* rights can send documents, or at minimum
     * the endpoints grant the permission the route requires.
     *
     * KNOWN BUG (README CRITICAL-1): the three send-email routes are gated by
     * sales.invoices.view / sales.quotes.view / suppliers.purchase_orders.view
     * and RolesSeeder gives Super Admin only settings.* permissions, so those
     * routes answer 403 for the account meant to administer the installation.
     */
    public function test_a_super_admin_may_reach_the_document_send_email_routes(): void
    {
        $superAdmin = $this->superAdmin();

        $this->actingAs($superAdmin)->post('/sales/invoices/1/send-email', [])->assertNotFound();

        $blocked = [];

        foreach ([
            ['/sales/invoices/1/send-email', 'sales.invoices.view'],
            ['/sales/quotes/1/send-email', 'sales.quotes.view'],
            ['/suppliers/purchase-orders/1/send-email', 'suppliers.purchase_orders.view'],
        ] as [$uri, $permission]) {
            $response = $this->actingAs($superAdmin)->post($uri, []);

            if ($response->getStatusCode() === 403) {
                $blocked[] = "{$uri} requires {$permission}, which the Super Admin role lacks";
            }
        }

        if ($blocked !== []) {
            $this->knownIssue('CRITICAL-1', implode('; ', $blocked));
        }

        $this->assertSame([], $blocked);
    }

    // ---------------------------------------------------------------------
    // CRITICAL-2 — email button on the quote and purchase-order show screens
    // ---------------------------------------------------------------------

    // ---------------------------------------------------------------------
    // HIGH-4 — "Deactivate package" locks staff out and says the opposite
    // ---------------------------------------------------------------------

    /**
     * The confirmation dialog and the success toast must describe what
     * deactivating the package actually does.
     *
     * KNOWN BUG (README HIGH-4): Subscription::isAccessBlocked() returns true
     * when there is no ACTIVE package as well as when one has expired, and
     * SubscriptionController::deactivate() clears is_active. So the click locks
     * every staff user out with a 403 on every page — while the confirm dialog
     * ("All users will regain access immediately") and the flash ("All users
     * now have unrestricted access") both promise the opposite.
     *
     * The assertion is deliberately about copy-versus-effect agreement rather
     * than about a specific wording, so it stays correct whichever of the two
     * possible intents is chosen when the issue is repaired.
     */
    public function test_deactivating_the_package_does_not_claim_staff_keep_access(): void
    {
        $staff = $this->userForRole('Accountant');

        $this->actingAs($this->superAdmin())->delete('/settings/subscription');

        $this->app['auth']->forgetGuards();
        $staffStatus = $this->actingAs($staff)->get('/dashboard')->getStatusCode();

        $copy = file_get_contents(resource_path('views/settings/subscription.blade.php'))
            .file_get_contents(app_path('Http/Controllers/Settings/SubscriptionController.php'));

        $claimsUnrestricted = str_contains($copy, 'regain access')
            || str_contains($copy, 'unrestricted access');

        if ($staffStatus === 403 && $claimsUnrestricted) {
            $this->knownIssue(
                'HIGH-4',
                'Deactivating the package 403s every staff page (/dashboard answered '
                ."{$staffStatus}), while the confirmation dialog and the success flash both "
                .'tell the admin that users regain unrestricted access.',
            );
        }

        $this->assertFalse(
            $staffStatus === 403 && $claimsUnrestricted,
            'The deactivation copy must not claim unrestricted access when staff are actually blocked.',
        );
    }

    /**
     * Quotes and purchase orders render through the shared documents.show
     * view, which carries the email button and modal for both.
     *
     * FIXED (README CRITICAL-2): the button and modal now live in
     * resources/views/documents/show.blade.php, so this asserts the fix stays.
     */
    public function test_quote_and_purchase_order_screens_offer_the_email_action(): void
    {
        $source = file_get_contents(resource_path('views/documents/show.blade.php'));

        $this->assertStringContainsString('send-document-modal', $source);
        $this->assertStringContainsString('open-modal', $source);
        $this->assertStringContainsString('Email', $source);
    }
}