<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\TaxRate;
use App\Services\Accounting\TaxCalculator;
use App\Support\DiscountLimit;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * Documents are wired to the tax engine.
 *
 * The engine existed but nothing used it: every line still carried a hand-typed
 * tax_percent, so a document could be issued at any rate the typist chose --
 * including a rate no longer in force, or one that does not exist. Collected tax
 * would then reconcile against nothing on the return.
 *
 * Two halves, both needed:
 *
 *  - The editor tells the user what rate is in force, so they do not have to know.
 *  - Submission is refused when the typed rate disagrees with that rate.
 */
class TaxRateDocumentWiringTest extends TestCase
{
    use SeedsDatabase;

    private function rate(array $overrides = []): TaxRate
    {
        return TaxRate::create(array_merge([
            'code' => 'WIRED-'.uniqid(),
            'name' => 'Standard VAT',
            'rate' => '17.0000',
            'applies_to' => 'sales',
            'effective_from' => '2026-01-01',
            'is_active' => true,
            'account_id' => Account::where('code', '2200')->value('id'),
        ], $overrides));
    }

    public function test_a_matching_rate_is_accepted(): void
    {
        $this->rate(['rate' => '17.0000']);

        $problems = app(TaxCalculator::class)
            ->validatePercents(['17', '17'], 'sales', '2026-06-01');

        $this->assertSame([], $problems);
    }

    public function test_a_rate_that_disagrees_with_the_catalogue_is_reported(): void
    {
        $this->rate(['rate' => '17.0000']);

        $problems = app(TaxCalculator::class)
            ->validatePercents(['5'], 'sales', '2026-06-01');

        $this->assertCount(1, $problems);
        $this->assertSame('5.00', $problems[0]['submitted']);
        $this->assertSame('17.0000', $problems[0]['expected']);
        $this->assertStringContainsString('17.0000%', $problems[0]['reason']);
    }

    /**
     * The document's own date decides, not today. An invoice raised for a month
     * that ran under the old rate must validate against that rate.
     */
    public function test_the_documents_date_decides_which_rate_applies(): void
    {
        $this->rate(['code' => 'OLD', 'rate' => '17.0000', 'effective_from' => '2026-01-01', 'effective_to' => '2026-06-30']);
        $this->rate(['code' => 'NEW', 'rate' => '20.0000', 'effective_from' => '2026-07-01']);

        $calculator = app(TaxCalculator::class);

        $this->assertSame([], $calculator->validatePercents(['17'], 'sales', '2026-03-01'));
        $this->assertCount(1, $calculator->validatePercents(['17'], 'sales', '2026-08-01'));
    }

    /**
     * Four-decimal rates must survive validation. Comparing at money scale would
     * reject a perfectly correct line.
     */
    public function test_a_four_decimal_rate_validates_exactly(): void
    {
        $this->rate(['rate' => '16.6667']);

        $this->assertSame(
            [],
            app(TaxCalculator::class)->validatePercents(['16.6667'], 'sales', '2026-06-01'),
            'a four-decimal rate was rejected because of its scale',
        );
    }

    /**
     * No rate configured, but tax charged anyway. That is the dangerous case: the
     * document collects money that reconciles against nothing.
     */
    public function test_tax_charged_with_no_configured_rate_is_reported(): void
    {
        $problems = app(TaxCalculator::class)
            ->validatePercents(['17'], 'sales', '2026-06-01');

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('No tax rate is configured', $problems[0]['reason']);
    }

    public function test_no_tax_is_fine_when_no_rate_is_configured(): void
    {
        $this->assertSame(
            [],
            app(TaxCalculator::class)->validatePercents(['0', '0'], 'sales', '2026-06-01'),
        );
    }

    public function test_tax_charged_against_an_exempt_rate_is_reported(): void
    {
        $this->rate(['is_exempt' => true]);

        $problems = app(TaxCalculator::class)
            ->validatePercents(['17'], 'sales', '2026-06-01');

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('exempt', $problems[0]['reason']);
    }

    public function test_the_editor_tells_the_user_which_rate_applies(): void
    {
        $this->rate(['name' => 'Standard VAT', 'rate' => '17.0000']);

        $hint = $this->hintController()->taxRateHint('sales', '2026-06-01');

        $this->assertSame('17.0000', $hint['percent']);
        $this->assertSame('Standard VAT', $hint['rate_name']);
        $this->assertNull($hint['reason']);
    }

    /**
     * A missing rate must read as "not set up", never as a zero rate. On a return
     * those are entirely different facts.
     */
    public function test_a_missing_rate_is_shown_as_missing_not_as_zero(): void
    {
        $hint = $this->hintController()->taxRateHint('sales', '2026-06-01');

        $this->assertNull($hint['percent']);
        $this->assertStringContainsString('No tax rate is configured', $hint['reason']);
    }

    public function test_a_compound_rate_says_so_in_the_hint(): void
    {
        $this->rate(['rate' => '20.0000', 'is_compound' => true]);

        $hint = $this->hintController()->taxRateHint('sales', '2026-06-01');

        $this->assertStringContainsString('tax as well as the net', $hint['reason']);
    }

    public function test_the_invoice_editor_shows_the_rate_hint(): void
    {
        $this->rate(['name' => 'Standard VAT', 'rate' => '17.0000']);

        $html = $this->actingAs($this->userForRole('Admin'))
            ->get('/sales/invoices/create')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Standard VAT', $html, 'the editor did not show the rate in force');
    }

    /**
     * The guard must not fire for a user who cannot see tax rates at all, or
     * nobody could raise a document until the catalogue was filled in.
     */
    public function test_validation_is_skipped_without_the_tax_permission(): void
    {
        $this->rate(['rate' => '17.0000']);

        $controller = new class extends \App\Http\Controllers\Controller
        {
            use DiscountLimit;
        };

        $staff = $this->staffUser();
        $this->assertFalse(
            $staff->can('accounting.tax_rates.view'),
            'the test user unexpectedly holds the tax permission',
        );

        auth()->login($staff);

        // Must not throw despite the rate disagreeing.
        $controller->validateTaxRates([['tax_percent' => 5]], 'sales', '2026-06-01');

        $this->assertTrue(true, 'validation ran for a user without the permission');
    }

    /**
     * DiscountLimit is a controller trait, so it cannot be resolved from the
     * container. Instantiated on a throwaway controller that mixes it in, which
     * is how a real controller gets it.
     */
    private function hintController(): object
    {
        return new class extends \App\Http\Controllers\Controller
        {
            use DiscountLimit;
        };
    }

    private function staffUser()
    {
        return \App\Models\User::create([
            'name' => 'Staff',
            'email' => 'staff-tax-'.uniqid().'@example.test',
            'password' => bcrypt('secret1234'),
        ]);
    }
}