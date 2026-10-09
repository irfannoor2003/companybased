<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\TaxRate;
use App\Services\Accounting\TaxCalculator;
use App\Support\DocumentItems;
use App\Support\Money;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * The tax rate catalogue and calculator.
 *
 * The application had a `tax_rates.view` / `tax_rates.manage` permission and no
 * table behind it. Every document line carried a hand-typed `tax_percent`, so the
 * rate was re-entered by hand on each invoice, nothing validated it, and changing
 * the rate meant editing documents already issued.
 *
 * The rules that matter and that these tests pin:
 *
 *  - Rates are effective dated. A change never restates a document already issued.
 *  - Overlapping rates are resolved by priority, not arbitrarily.
 *  - A missing rate is reported as a gap, and is NOT the same as an exempt rate.
 *  - Compound tax is computed so the declared rate stays true of the final figure.
 *  - Thresholds exempt small transactions without touching the rate itself.
 *  - Tax is charged on the net, after discount, never on the gross.
 */
class TaxRateEngineTest extends TestCase
{
    use SeedsDatabase;

    private function calculator(): TaxCalculator
    {
        return app(TaxCalculator::class);
    }

    private function rate(array $overrides = []): TaxRate
    {
        return TaxRate::create(array_merge([
            'code' => 'STANDARD-'.uniqid(),
            'name' => 'Standard rate',
            'rate' => '17.0000',
            'applies_to' => 'sales',
            'effective_from' => '2026-01-01',
            'priority' => 0,
            'is_active' => true,
            'account_id' => Account::where('code', '2200')->value('id'),
        ], $overrides));
    }

    public function test_a_rate_resolves_for_a_date(): void
    {
        $this->rate(['rate' => '17.0000', 'effective_from' => '2026-01-01']);

        $resolved = $this->calculator()->rateFor('sales', '2026-06-01');

        $this->assertNotNull($resolved);
        $this->assertSame('17.0000', (string) $resolved->rate);
    }

    /**
     * The point of effective dating. A rate changed mid-year must not reach back
     * and restate a document issued before the change.
     */
    public function test_a_rate_change_does_not_reach_back_to_earlier_documents(): void
    {
        $original = $this->rate([
            'code' => 'OLD',
            'rate' => '17.0000',
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-06-30',
        ]);

        $this->rate([
            'code' => 'NEW',
            'rate' => '20.0000',
            'effective_from' => '2026-07-01',
        ]);

        $this->assertSame(
            '17.0000',
            (string) $this->calculator()->rateFor('sales', '2026-03-15')->rate,
            'a March document picked up the July rate',
        );

        $this->assertSame(
            '20.0000',
            (string) $this->calculator()->rateFor('sales', '2026-08-15')->rate,
            'an August document did not pick up the current rate',
        );

        $this->assertNotNull($original);
    }

    public function test_a_rate_not_yet_in_force_is_not_applied(): void
    {
        $this->rate(['rate' => '17.0000', 'effective_from' => '2026-07-01']);

        $this->assertNull(
            $this->calculator()->rateFor('sales', '2026-03-01'),
            'a rate effective from July was applied to a March document',
        );
    }

    /**
     * Overlapping ranges are legitimate: a standard rate and a reduced rate can
     * both apply on one day. Priority is what decides, and it must be honoured
     * rather than resolved by insertion order.
     */
    public function test_priority_decides_between_overlapping_rates(): void
    {
        $this->rate(['code' => 'STANDARD', 'rate' => '17.0000', 'priority' => 0]);

        $this->rate(['code' => 'REDUCED', 'rate' => '5.0000', 'priority' => 10]);

        $this->assertSame(
            'REDUCED',
            $this->calculator()->rateFor('sales', '2026-06-01')->code,
            'the higher-priority rate did not win',
        );
    }

    public function test_an_inactive_rate_is_ignored(): void
    {
        $this->rate(['rate' => '17.0000', 'is_active' => false]);

        $this->assertNull($this->calculator()->rateFor('sales', '2026-06-01'));
    }

    /**
     * A missing rate and an exempt rate are different facts, and a return depends
     * on the difference: one is an administrative gap, the other belongs in the
     * exempt box.
     */
    public function test_a_missing_rate_is_reported_as_a_gap_not_as_an_exemption(): void
    {
        $result = $this->calculator()->onAmount('1000.00', 'sales', '2026-06-01');

        $this->assertSame('0.00', $result['tax']);
        $this->assertFalse($result['exempt'], 'a missing rate was reported as an exemption');
        $this->assertNull($result['rate_id']);
        $this->assertStringContainsString('No tax rate is configured', $result['reason']);
    }

    public function test_an_exempt_rate_is_reported_as_exempt(): void
    {
        $this->rate(['is_exempt' => true]);

        $result = $this->calculator()->onAmount('1000.00', 'sales', '2026-06-01');

        $this->assertSame('0.00', $result['tax']);
        $this->assertTrue($result['exempt']);
        $this->assertStringContainsString('exempt', $result['reason']);
    }

    public function test_tax_is_charged_on_the_net_at_the_configured_rate(): void
    {
        $this->rate(['rate' => '17.0000']);

        $result = $this->calculator()->onAmount('1000.00', 'sales', '2026-06-01');

        $this->assertSame('170.00', $result['tax']);
        $this->assertFalse($result['exempt']);
    }

    /**
     * A threshold regime exempts small transactions without altering the rate, so
     * the rate row stays true for everyone above it.
     */
    public function test_a_threshold_exempts_small_amounts(): void
    {
        $this->rate(['rate' => '17.0000', 'threshold_amount' => '500.00']);

        $this->assertSame(
            '0.00',
            $this->calculator()->onAmount('499.99', 'sales', '2026-06-01')['tax'],
        );

        $this->assertSame(
            '170.00',
            $this->calculator()->onAmount('1000.00', 'sales', '2026-06-01')['tax'],
            'an amount above the threshold was exempt',
        );
    }

    /**
     * Compound tax: the declared rate must remain true of the final figure.
     *
     * Naively charging 20% of the net gives 120 on 100, which is a 20% charge on
     * the net but only a 16.67% charge on the 120 actually collected. Dividing by
     * (1 - rate) makes the rate true of the gross.
     */
    public function test_compound_tax_keeps_the_rate_true_of_the_gross(): void
    {
        $this->rate(['rate' => '20.0000', 'is_compound' => true]);

        $tax = $this->calculator()->onAmount('100.00', 'sales', '2026-06-01')['tax'];

        $this->assertSame('25.00', $tax, 'compound tax on 100 at 20% should be 25');

        // 100 + 25 = 125 gross, and 20% of 125 is 25.
        $this->assertSame('25.00', Money::percentageOf('125.00', '20'), 'the rate is not true of the gross');
    }

    public function test_non_compound_tax_charges_only_on_the_net(): void
    {
        $this->rate(['rate' => '20.0000', 'is_compound' => false]);

        $this->assertSame('20.00', $this->calculator()->onAmount('100.00', 'sales', '2026-06-01')['tax']);
    }

    public function test_a_purchase_rate_is_distinct_from_a_sales_rate(): void
    {
        $this->rate(['code' => 'SALES', 'rate' => '17.0000', 'applies_to' => 'sales']);
        $this->rate(['code' => 'INPUT', 'rate' => '17.0000', 'applies_to' => 'purchase']);

        $this->assertSame('SALES', $this->calculator()->rateFor('sales', '2026-06-01')->code);
        $this->assertSame('INPUT', $this->calculator()->rateFor('purchase', '2026-06-01')->code);
    }

    public function test_a_rate_applicable_to_both_is_used_when_no_specific_rate_exists(): void
    {
        $this->rate(['code' => 'BOTH', 'rate' => '10.0000', 'applies_to' => 'both']);

        $this->assertSame('BOTH', $this->calculator()->rateFor('sales', '2026-06-01')->code);
        $this->assertSame('BOTH', $this->calculator()->rateFor('purchase', '2026-06-01')->code);
    }

    /**
     * The effective percentage is what gets written onto a document line, and
     * DocumentItems computes from that. So it must match the tax actually charged,
     * including compound.
     */
    public function test_the_effective_percentage_matches_the_tax_charged(): void
    {
        $this->rate(['rate' => '20.0000', 'is_compound' => true]);

        $percent = $this->calculator()->effectivePercentFor('100.00', 'sales', '2026-06-01');

        // 25 on a 100 net is an effective 25%.
        $this->assertSame('25.0000', $percent);

// And feeding that back through DocumentItems reproduces the same tax, which is
        // the point: the percentage written onto the line has to round-trip.
        $line = DocumentItems::lineTotals(1, 100.00, 0, (float) $percent);
        $this->assertEqualsWithDelta(25.00, $line['line_tax'], 0.005);
    }

    public function test_the_effective_percentage_of_a_zero_line_is_zero(): void
    {
        $this->rate(['rate' => '17.0000']);

        $this->assertSame(
            '0.0000',
            $this->calculator()->effectivePercentFor('0', 'sales', '2026-06-01'),
        );
    }

    /**
     * The ledger account travels with the rate, so where collected tax lands is a
     * property of the rate rather than hard-coded in the posting rules.
     */
    public function test_the_rate_carries_its_ledger_account(): void
    {
        $rate = $this->rate(['rate' => '17.0000']);

        $this->assertSame(
            '2200',
            $rate->account->code,
            'the rate is not linked to the output tax account',
        );
    }

    public function test_superseding_a_rate_leaves_no_window_without_one(): void
    {
        $old = $this->rate(['rate' => '17.0000', 'effective_from' => '2026-01-01']);

        $this->rate(['rate' => '20.0000', 'effective_from' => '2026-07-01']);

        $old->supersededFrom('2026-06-30');

        $this->assertSame('17.0000', (string) $this->calculator()->rateFor('sales', '2026-06-30')->rate);
        $this->assertSame('20.0000', (string) $this->calculator()->rateFor('sales', '2026-07-01')->rate);
    }

    public function test_the_rate_label_drops_trailing_zeros(): void
    {
        $this->assertSame('17%', $this->rate(['rate' => '17.0000'])->label());
        $this->assertSame('16.6667%', $this->rate(['rate' => '16.6667'])->label());
    }
}