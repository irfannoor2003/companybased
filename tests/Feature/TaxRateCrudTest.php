<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\TaxRate;
use App\Services\Accounting\TaxCalculator;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * The tax rate screen.
 *
 * The accounting.tax_rates permissions existed with no routes behind them, so the
 * engine resolved a rate per document date while there was no way to create one.
 * Every document therefore reported "no tax rate is configured" and charged
 * nothing.
 *
 * The behaviour that matters most is supersession. A rate is never edited in
 * place, because documents already issued under it have gone to customers:
 * editing would retroactively change what they should have been charged. The
 * replacement takes effect and the old row is closed the day before.
 */
class TaxRateCrudTest extends TestCase
{
    use SeedsDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Standard VAT',
            'code' => 'VAT-'.uniqid(),
            'rate' => '17.0000',
            'applies_to' => 'sales',
            'effective_from' => today()->toDateString(),
            'priority' => 0,
            'is_active' => 1,
            'account_id' => Account::where('code', '2200')->value('id'),
        ], $overrides);
    }

    public function test_the_list_renders(): void
    {
        TaxRate::create($this->payload(['name' => 'Standard VAT', 'rate' => '17.0000']));

        $this->actingAs($this->userForRole('Admin'))
            ->get('/accounting/tax-rates')
            ->assertOk()
            ->assertSee('Standard VAT');
    }

    public function test_the_create_screen_renders(): void
    {
        $this->actingAs($this->userForRole('Admin'))
            ->get('/accounting/tax-rates/create')
            ->assertOk();
    }

    public function test_a_rate_can_be_created(): void
    {
        $this->actingAs($this->userForRole('Admin'))
            ->post('/accounting/tax-rates', $this->payload(['rate' => '17.0000']))
            ->assertRedirect('/accounting/tax-rates')
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('tax_rates', ['rate' => '17.0000', 'applies_to' => 'sales']);
    }

    /**
     * With no rate in force the screen must say so plainly. A user needs to know
     * the engine is charging nothing, rather than inferring it from a zero.
     */
    public function test_the_list_warns_when_no_rate_is_in_force(): void
    {
        $this->actingAs($this->userForRole('Admin'))
            ->get('/accounting/tax-rates')
            ->assertOk()
            ->assertSee('No sales tax rate is in force');
    }

    public function test_the_list_names_the_rate_currently_applied(): void
    {
        TaxRate::create($this->payload(['name' => 'Standard VAT', 'rate' => '17.0000']));

        $this->actingAs($this->userForRole('Admin'))
            ->get('/accounting/tax-rates')
            ->assertOk()
            ->assertSee('Currently applied to sales documents');
    }

    /**
     * The core guarantee. A replacement supersedes; the original is closed, not
     * rewritten.
     */
    public function test_superseding_closes_the_old_rate_and_leaves_a_replacement(): void
    {
        $original = TaxRate::create($this->payload([
            'code' => 'VAT-ORIGINAL',
            'rate' => '17.0000',
            'effective_from' => today()->subDays(60)->toDateString(),
        ]));

        $this->actingAs($this->userForRole('Admin'))
            ->put('/accounting/tax-rates/'.$original->id, [
                'name' => 'Standard VAT 2027',
                'rate' => '20.0000',
                'applies_to' => 'sales',
                'effective_from' => today()->toDateString(),
                'priority' => 0,
                'is_active' => 1,
                'account_id' => Account::where('code', '2200')->value('id'),
            ])
            ->assertRedirect('/accounting/tax-rates');

        // The original is untouched as to its rate, and now closed.
        $original->refresh();
        $this->assertSame('17.0000', (string) $original->rate, 'the superseded rate was rewritten');
        $this->assertNotNull($original->effective_to, 'the superseded rate was not closed');

        $this->assertDatabaseHas('tax_rates', ['rate' => '20.0000']);
    }

    /**
     * Documents issued before the change must still resolve to the old rate.
     */
    public function test_documents_issued_before_a_change_keep_the_old_rate(): void
    {
        $original = TaxRate::create($this->payload([
            'code' => 'VAT-OLD',
            'rate' => '17.0000',
            'effective_from' => today()->subDays(90)->toDateString(),
        ]));

        $this->actingAs($this->userForRole('Admin'))
            ->put('/accounting/tax-rates/'.$original->id, [
                'name' => 'Replacement',
                'rate' => '20.0000',
                'applies_to' => 'sales',
                'effective_from' => today()->toDateString(),
                'priority' => 0,
                'is_active' => 1,
            ]);

        $calculator = app(TaxCalculator::class);

        $this->assertSame(
            '17.0000',
            (string) $calculator->rateFor('sales', today()->subDays(30))->rate,
            'a document issued last month picked up the new rate',
        );

        $this->assertSame(
            '20.0000',
            (string) $calculator->rateFor('sales', today()->addDay())->rate,
        );
    }

    /**
     * A backdated replacement would leave a window with no rate at all, and
     * documents issued in that window would stop validating.
     */
    public function test_a_backdated_replacement_is_refused(): void
    {
        $rate = TaxRate::create($this->payload([
            'rate' => '17.0000',
            'effective_from' => today()->subDays(30)->toDateString(),
        ]));

        $this->actingAs($this->userForRole('Admin'))
            ->put('/accounting/tax-rates/'.$rate->id, [
                'name' => 'Backdated',
                'rate' => '20.0000',
                'applies_to' => 'sales',
                'effective_from' => today()->subDays(10)->toDateString(),
                'priority' => 0,
                'is_active' => 1,
            ])
            ->assertSessionHas('toasts');

        $this->assertDatabaseMissing('tax_rates', ['rate' => '20.0000']);
    }

    /**
     * A rate that has already taken effect cannot be deleted. Documents issued
     * under it would stop resolving to anything, with no record of what they were
     * charged.
     */
    public function test_a_rate_already_in_force_cannot_be_deleted(): void
    {
        $rate = TaxRate::create($this->payload([
            'rate' => '17.0000',
            'effective_from' => today()->subDays(30)->toDateString(),
        ]));

        $this->actingAs($this->userForRole('Admin'))
            ->from('/accounting/tax-rates')
            ->delete('/accounting/tax-rates/'.$rate->id)
            ->assertSessionHas('toasts');

        $this->assertDatabaseHas('tax_rates', ['id' => $rate->id]);
    }

    /**
     * A future rate has never applied to anything, so deleting it loses nothing.
     */
    public function test_a_future_rate_can_be_deleted(): void
    {
        $rate = TaxRate::create($this->payload([
            'effective_from' => today()->addMonth()->toDateString(),
        ]));

        $this->actingAs($this->userForRole('Admin'))
            ->from('/accounting/tax-rates')
            ->delete('/accounting/tax-rates/'.$rate->id)
            ->assertRedirect();

        $this->assertSoftDeleted('tax_rates', ['id' => $rate->id]);
    }

    public function test_a_rate_can_be_disabled_without_being_deleted(): void
    {
        $rate = TaxRate::create($this->payload([
            'rate' => '17.0000',
            'effective_from' => today()->subDays(30)->toDateString(),
        ]));

        $this->actingAs($this->userForRole('Admin'))
            ->from('/accounting/tax-rates')
            ->post('/accounting/tax-rates/'.$rate->id.'/toggle')
            ->assertRedirect();

        $this->assertFalse($rate->fresh()->is_active);
        $this->assertNull(
            app(TaxCalculator::class)->rateFor('sales', today()),
            'a disabled rate is still being applied',
        );
    }

    /**
     * A zero rate, an exemption, and a threshold all mean "charge nothing", but
     * three configurations for one outcome will eventually disagree on a return.
     */
    public function test_a_zero_rate_is_normalised_to_exempt(): void
    {
        $this->actingAs($this->userForRole('Admin'))
            ->post('/accounting/tax-rates', $this->payload(['rate' => '0']))
            ->assertRedirect();

        $this->assertDatabaseHas('tax_rates', ['is_exempt' => true]);
    }

    /**
     * Overlapping ranges at the same priority leave "which rate applies" ambiguous
     * on the days they coincide.
     */
    public function test_an_overlapping_range_is_refused(): void
    {
        TaxRate::create($this->payload([
            'rate' => '17.0000',
            'effective_from' => today()->toDateString(),
            'effective_to' => today()->addMonth()->toDateString(),
        ]));

        $this->actingAs($this->userForRole('Admin'))
            ->post('/accounting/tax-rates', $this->payload([
                'rate' => '20.0000',
                'effective_from' => today()->addDays(10)->toDateString(),
            ]))
            ->assertSessionHas('toasts');

        $this->assertDatabaseMissing('tax_rates', ['rate' => '20.0000']);
    }

    public function test_a_duplicate_code_is_refused(): void
    {
        TaxRate::create($this->payload(['code' => 'VAT-DUP']));

        $this->actingAs($this->userForRole('Admin'))
            ->post('/accounting/tax-rates', $this->payload(['code' => 'VAT-DUP']))
            ->assertSessionHasErrors('code');
    }

/**
     * The nav entry must point at a route that exists, or the sidebar link is a
     * dead end even though the screen itself works.
 */
public function test_the_navigation_entry_points_at_a_real_route(): void
{
    $this->actingAs($this->userForRole('Admin'))
        ->get(route('accounting.tax_rates.index'))
        ->assertOk();
}

    /**
     * The permission must exist under accounting, where the screen lives, not
     * under company settings -- otherwise the nav entry never renders and the
     * feature is unreachable.
     */
    public function test_the_permissions_live_under_accounting(): void
    {
        $all = \App\Support\Permissions::all();

        foreach (['view', 'manage', 'delete'] as $action) {
            $this->assertContains('accounting.tax_rates.'.$action, $all);
        }
    }
}