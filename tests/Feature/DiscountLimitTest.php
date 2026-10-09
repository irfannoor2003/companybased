<?php

namespace Tests\Feature;

use App\Models\DiscountRule;
use App\Models\SalesInvoice;
use App\Models\User;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * validateDiscountLimits() used to abort() with response()->json(..., 422).
 *
 * All four call sites -- invoice, quote, order and credit note -- are plain
 * browser form posts. A browser that receives a JSON body instead of a 302 is
 * left staring at raw text: no redirect back to the form, no field errors, no way
 * back into the app, and the entered line items gone. The server-side check was
 * correct; only the way it reported failure was wrong.
 *
 * These tests go through a real POST, because the regression is about what the
 * browser receives rather than about the exception type in isolation.
 */
class DiscountLimitTest extends TestCase
{
    use SeedsDatabase;

    /**
     * A percentage cap for one role. getMaxDiscountForUser() takes the minimum
     * across every active rule that applies to the user's roles.
     */
    private function capFor(string $role, string $maxPercent): DiscountRule
    {
        return DiscountRule::create([
            'name' => 'Standard cap',
            'type' => 'percentage',
            'max_value' => $maxPercent,
            'applicable_to' => 'role',
            'roles' => [$role],
            'is_active' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(int $customerId, string $discountPercent): array
    {
        return [
            'customer_id' => $customerId,
            'issue_date' => now()->toDateString(),
            'status' => 'draft',
            'currency' => 'USD',
            'items' => [
                [
                    'description' => 'Consulting',
                    'qty' => '1',
                    'unit_price' => '100.00',
                    'discount_percent' => $discountPercent,
                    'tax_percent' => '0',
                ],
            ],
        ];
    }

    private ?User $admin = null;

    /**
     * userForRole() creates a user per call, and two calls in one test collide on
     * the unique email, so the actor is memoised.
     */
    private function admin(): User
    {
        return $this->admin ??= $this->userForRole('Admin');
    }

    /**
     * Post the invoice form as $role and return the response.
     *
     * @param  array<string, mixed>  $payload
     */
    private function postInvoice(array $payload, ?string $role = null)
    {
        return $this->actingAs($role ? $this->userForRole($role) : $this->admin())
            ->from('/sales/invoices/create')
            ->post('/sales/invoices', $payload);
    }

    /**
     * The line-item editor's error paragraph, as the user actually receives it.
     *
     * Rather than reaching into the flashed session -- whose shape differs between
     * a ViewErrorBag and the raw array it wraps -- this follows the redirect and
     * reads the rendered error out of the HTML. That is the outcome that matters:
     * the message has to reach the screen.
     */
    private function errorTextOnScreen(string $html): string
    {
        preg_match_all('/<p class="mt-1\.5 text-xs text-rose-600">(.*?)<\/p>/s', $html, $matches);

        return implode(' | ', $matches[1]);
    }

    public function test_an_over_limit_discount_redirects_back_with_a_validation_error(): void
    {
        $this->capFor('Admin', '10');

        // The regression: this used to be a 422 carrying a JSON body.
        $this->postInvoice($this->payload($this->customer()->id, '25'))
            ->assertRedirect('/sales/invoices/create')
            ->assertSessionHasErrors('items');
    }

    /**
     * The line-item editor renders only @error('items'). Laravel's has() does not
     * match a dotted child against a flat message bag, so an exception keyed only
     * to items.0.discount_percent would be collected but never displayed.
     */
    public function test_the_message_reaches_the_screen(): void
    {
        $this->capFor('Admin', '10');

        $html = $this->followingRedirects()
            ->postInvoice($this->payload($this->customer()->id, '25'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'exceeds your maximum allowed discount',
            $this->errorTextOnScreen($html),
            'the editor renders @error(\'items\'); the message must reach the screen, not just the session',
        );
    }

    public function test_the_message_names_the_line_and_both_percentages(): void
    {
        $this->capFor('Admin', '10');

        $html = $this->followingRedirects()
            ->postInvoice($this->payload($this->customer()->id, '25'))
            ->assertOk()
            ->getContent();

        $message = $this->errorTextOnScreen($html);

        $this->assertStringContainsString('line 1', $message);
        $this->assertStringContainsString('25', $message, 'the offending discount is named');
        $this->assertStringContainsString('10', $message, 'the permitted maximum is named');
    }

    /**
     * Percentages must not be rendered as "25.00%" -- the rule is authored in
     * whole numbers and the trailing zeros read as a different figure.
     */
    public function test_percentages_are_rendered_without_trailing_zeros(): void
    {
        $this->capFor('Admin', '10');

        $html = $this->followingRedirects()
            ->postInvoice($this->payload($this->customer()->id, '25'))
            ->assertOk()
            ->getContent();

        $message = $this->errorTextOnScreen($html);

        $this->assertStringNotContainsString('25.00%', $message);
        $this->assertStringNotContainsString('10.00%', $message);
    }

    /**
     * The specific key is kept alongside the aggregate one so callers can inspect
     * which line was rejected.
     */
    public function test_the_offending_line_is_identified_by_a_dotted_key(): void
    {
        $this->capFor('Admin', '10');

        $this->postInvoice($this->payload($this->customer()->id, '25'))
            ->assertSessionHasErrors(['items.0.discount_percent']);
    }

    /**
     * Nothing is created when the discount is refused.
     */
    public function test_no_invoice_is_created_when_the_discount_is_refused(): void
    {
        $this->capFor('Admin', '10');

        $this->postInvoice($this->payload($this->customer()->id, '25'));

        $this->assertSame(0, SalesInvoice::count());
    }

    public function test_a_discount_at_the_cap_is_allowed(): void
    {
        $this->capFor('Admin', '10');

        $this->postInvoice($this->payload($this->customer()->id, '10'))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, SalesInvoice::count());
    }

    public function test_no_rule_means_no_limit_is_enforced(): void
    {
        // With no DiscountRule the check returns early, so any discount stands.
        $this->postInvoice($this->payload($this->customer()->id, '90'))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, SalesInvoice::count());
    }

    /**
     * The cap is per role. A rule naming a different role must not constrain this
     * user, otherwise every discount limit applies to the whole company.
     */
    public function test_a_rule_for_another_role_does_not_constrain_this_user(): void
    {
        $this->capFor('Inventory Manager', '5');

        $this->postInvoice($this->payload($this->customer()->id, '40'))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, SalesInvoice::count());
    }

    public function test_an_inactive_rule_is_ignored(): void
    {
        $rule = $this->capFor('Admin', '5');
        $rule->update(['is_active' => false]);

        $this->postInvoice($this->payload($this->customer()->id, '40'))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, SalesInvoice::count());
    }

    /**
     * The tightest applicable rule wins.
     */
    public function test_the_tightest_applicable_rule_wins(): void
    {
        $this->capFor('Admin', '30');
        $this->capFor('Admin', '12');

        $this->postInvoice($this->payload($this->customer()->id, '20'))
            ->assertSessionHasErrors('items');

        $this->assertSame(0, SalesInvoice::count());
    }

    /**
     * The offending line is the one that is named, not merely the first.
     */
    public function test_the_rejected_line_number_is_reported(): void
    {
        $this->capFor('Admin', '10');

        $payload = $this->payload($this->customer()->id, '0');
        $payload['items'][] = [
            'description' => 'Support',
            'qty' => '1',
            'unit_price' => '50.00',
            'discount_percent' => '80',
            'tax_percent' => '0',
        ];

        $this->postInvoice($payload)
            ->assertSessionHasErrors(['items.1.discount_percent']);

        $html = $this->followingRedirects()
            ->postInvoice($payload)
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('line 2', $this->errorTextOnScreen($html));
    }
}
