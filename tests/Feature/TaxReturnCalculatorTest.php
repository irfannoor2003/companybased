<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\SalesInvoice;
use App\Models\Supplier;
use App\Models\TaxReturn;
use App\Services\Accounting\LedgerPosting;
use App\Services\Accounting\TaxReturnCalculator;
use App\Support\GeneralLedger;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * A tax return's figures are derived from the ledger, not typed in.
 *
 * Every figure on a return used to be a required form field. That is the weakest
 * possible position for the most consequential document the business produces: the
 * return cannot be reproduced from the books that generated it, two people typing
 * the same month will disagree, and a mistyped digit is invisible until the
 * authority rejects it.
 *
 * These tests pin the mapping and, more importantly, the properties that make the
 * number reproducible: posted entries only, a credit note reduces the period it
 * falls in, and a return that has been filed stops moving.
 */
class TaxReturnCalculatorTest extends TestCase
{
    use SeedsDatabase;

    /** Cached: two of these tests act as admin, and the helper's email is unique. */
    private ?\App\Models\User $admin = null;

    private function admin(): \App\Models\User
    {
        return $this->admin ??= $this->userForRole('Admin');
    }

    private function calculate(string $from = '2026-10-01', string $to = '2026-10-31'): array
    {
        return app(TaxReturnCalculator::class)->compute($from, $to);
    }

    /**
     * Post a journal by hand so the ledger figures are exact and not dependent on
     * document fixtures that also move stock.
     *
     * @param  array<string, array{0: string, 1: string}>  $lines  code => [debit, credit]
     */
    private function postJournal(string $date, array $lines, string $description = 'Fixture'): void
    {
        $entry = JournalEntry::create([
            'number' => 'JV-'.uniqid(),
            'entry_date' => $date,
            'reference' => 'fixture:'.uniqid(),
            'description' => $description,
            'status' => 'draft',
        ]);

        GeneralLedger::replaceLines($entry, collect($lines)->map(fn (array $sides, string $code) => [
            'account_id' => \App\Models\Account::where('code', $code)->value('id'),
            'debit' => $sides[0],
            'credit' => $sides[1],
        ])->values()->all());

        GeneralLedger::post($entry);
    }

    /**
     * A period with nothing in it computes to zero rather than failing, which is
     * what a nil return needs.
     */
    public function test_a_quiet_period_computes_to_zero(): void
    {
        $computed = $this->calculate();

        $this->assertSame('0.00', $computed['tax_collected']);
        $this->assertSame('0.00', $computed['tax_credits']);
        $this->assertSame('0.00', $computed['tax_due']);
        $this->assertFalse($computed['has_activity'], 'an empty ledger has no activity');
    }

    /**
     * The core case: output tax charged, input tax reclaimed, and the difference
     * owed.
     */
    public function test_tax_due_is_output_tax_less_input_tax(): void
    {
        // Output tax: Cr 2200 18,000 for sales. Balanced against receivables.
        $this->postJournal('2026-10-05', [
            '1100' => ['118000', '0'],
            '4000' => ['0', '100000'],
            '2200' => ['0', '18000'],
        ]);

        // Input tax: Dr 1310 3,000 on purchases.
        $this->postJournal('2026-10-12', [
            '1310' => ['3000', '0'],
            '5000' => ['7000', '0'],
            '2000' => ['0', '10000'],
        ]);

        $computed = $this->calculate();

        $this->assertSame('18000.00', $computed['tax_collected'], 'output tax is the movement on 2200');
        $this->assertSame('3000.00', $computed['tax_credits'], 'input tax is the movement on 1310');
        $this->assertSame('15000.00', $computed['tax_due']);
        $this->assertTrue($computed['has_activity']);
    }

    /**
     * Output tax is a credit-normal liability, so it is read as credits MINUS
     * debits. A credit note debits 2200 and must therefore reduce this period's
     * output tax, not increase it.
     */
    public function test_a_credit_note_reduces_this_periods_output_tax(): void
    {
        $this->postJournal('2026-10-05', [
            '1100' => ['118000', '0'],
            '4000' => ['0', '100000'],
            '2200' => ['0', '18000'],
        ]);

        $this->assertSame('18000.00', $this->calculate()['tax_collected']);

        // Credit note: Dr 2200 3,000 / Cr AR 3,000.
        $this->postJournal('2026-10-20', [
            '2200' => ['3000', '0'],
            '1100' => ['0', '3000'],
        ], 'Credit note');

        $this->assertSame('15000.00', $this->calculate()['tax_collected'], 'the credit note must reduce the period');
    }

    /**
     * Input tax is debit-normal, so it is read as debits MINUS credits. A debit
     * note refunds input tax and must reduce the credit claimed.
     */
    public function test_a_debit_note_reduces_this_periods_input_credit(): void
    {
        $this->postJournal('2026-10-12', [
            '1310' => ['3000', '0'],
            '5000' => ['7000', '0'],
            '2000' => ['0', '10000'],
        ]);

        $this->assertSame('3000.00', $this->calculate()['tax_credits']);

        $this->postJournal('2026-10-22', [
            '2000' => ['1000', '0'],
            '1310' => ['0', '1000'],
        ], 'Debit note');

        $this->assertSame('2000.00', $this->calculate()['tax_credits']);
    }

    /**
     * Posted entries only.
     *
     * A return that included a draft would move the moment somebody finished
     * approving an invoice -- which is exactly the quiet revision that makes a
     * filed return impossible to reproduce.
     */
    public function test_draft_entries_are_excluded(): void
    {
        $draft = JournalEntry::create([
            'number' => 'JV-'.uniqid(),
            'entry_date' => '2026-10-05',
            'reference' => 'draft:'.uniqid(),
            'description' => 'Not posted',
            'status' => 'draft',
        ]);

        GeneralLedger::replaceLines($draft, [
            ['account_id' => \App\Models\Account::where('code', '2200')->value('id'), 'debit' => '0', 'credit' => '99999.00'],
            ['account_id' => \App\Models\Account::where('code', '1100')->value('id'), 'debit' => '99999.00', 'credit' => '0'],
        ]);

        $this->assertSame('0.00', $this->calculate()['tax_collected'], 'a draft must not reach the return');
    }

    /**
     * The period boundaries are respected. Tax posted outside the period belongs to
     * a different return.
     */
    public function test_entries_outside_the_period_are_excluded(): void
    {
        $this->postJournal('2026-09-30', [
            '1100' => ['118000', '0'],
            '4000' => ['0', '100000'],
            '2200' => ['0', '18000'],
        ], 'Last month');

        $this->postJournal('2026-11-02', [
            '1100' => ['118000', '0'],
            '4000' => ['0', '100000'],
            '2200' => ['0', '18000'],
        ], 'Next month');

        $this->assertSame('0.00', $this->calculate()['tax_collected'], 'neither neighbouring month belongs here');
        $this->assertSame('18000.00', $this->calculate('2026-11-01', '2026-11-30')['tax_collected']);
        $this->assertSame('18000.00', $this->calculate('2026-09-01', '2026-09-30')['tax_collected']);
    }

    /**
     * Taxable value is what the revenue accounts were credited, which is already
     * net of tax, and gross receipts add the tax back on.
     *
     * Both come from the ledger. An earlier version read `sales_invoices.total` for
     * the gross while taking the tax from the ledger, and the two disagreed the
     * moment a document was saved without posting -- reporting a gross of 0 next to
     * 18,000 of tax collected.
     */
    public function test_taxable_amount_is_net_of_tax_and_gross_adds_it_back(): void
    {
        $this->postJournal('2026-10-05', [
            '1100' => ['118000', '0'],
            '4000' => ['0', '100000'],
            '2200' => ['0', '18000'],
        ]);

        $computed = $this->calculate();

        $this->assertSame('100000.00', $computed['taxable_amount'], 'revenue is credited net of tax');
        $this->assertSame('118000.00', $computed['gross_receipts'], 'gross receipts add the tax back on');
    }

    /**
     * Creating a return no longer demands five typed figures: the ledger supplies
     * them when the fields are left blank.
     */
    public function test_a_return_takes_its_figures_from_the_ledger_when_none_are_entered(): void
    {
        $this->postJournal('2026-10-05', [
            '1100' => ['118000', '0'],
            '4000' => ['0', '100000'],
            '2200' => ['0', '18000'],
        ]);

        $tax = $this->createReturn();

        $this->assertSame('18000.00', $tax->tax_collected);
        $this->assertSame('0.00', $tax->tax_credits);
        $this->assertSame('18000.00', $tax->tax_due);
    }

    /**
     * An explicitly entered figure still wins, because an authority's own basis may
     * not match a plain reading of the accounts and only the owner knows that.
     */
    public function test_an_entered_figure_overrides_the_ledger(): void
    {
        $this->postJournal('2026-10-05', [
            '1100' => ['118000', '0'],
            '4000' => ['0', '100000'],
            '2200' => ['0', '18000'],
        ]);

        $tax = $this->createReturn(['tax_collected' => '17500.00']);

        $this->assertSame('17500.00', $tax->tax_collected, 'the owner\'s figure must be respected');
    }

    /**
     * Recompute is how a draft is brought back in line with the books.
     */
    public function test_a_draft_return_can_be_recomputed_from_the_ledger(): void
    {
        $this->postJournal('2026-10-05', [
            '1100' => ['118000', '0'],
            '4000' => ['0', '100000'],
            '2200' => ['0', '18000'],
        ]);

        $tax = $this->createReturn(['tax_collected' => '1.00', 'tax_due' => '1.00']);

        $this->actingAs($this->admin())
            ->post("/accounting/tax-returns/{$tax->id}/recompute")
            ->assertRedirect();

        $tax->refresh();

        $this->assertSame('18000.00', $tax->tax_collected, 'recompute must overwrite the typed figure');
        $this->assertSame('18000.00', $tax->tax_due);
    }

    /**
     * A filed return is a historical statement. Rewriting its figures would make
     * the document that was actually filed irreproducible.
     */
    public function test_a_filed_return_cannot_be_recomputed(): void
    {
        $this->postJournal('2026-10-05', [
            '1100' => ['118000', '0'],
            '4000' => ['0', '100000'],
            '2200' => ['0', '18000'],
        ]);

        $tax = $this->createReturn(['tax_collected' => '1.00']);
        $tax->forceFill(['status' => 'filed', 'filed_at' => now()])->save();

        $this->actingAs($this->admin())
            ->post("/accounting/tax-returns/{$tax->id}/recompute")
            ->assertRedirect();

        $this->assertSame(
            '1.00',
            $tax->refresh()->tax_collected,
            'a filed return must keep the figures it was filed with',
        );
    }

    private function createReturn(array $overrides = []): TaxReturn
    {
        $response = $this->actingAs($this->admin())
            ->post('/accounting/tax-returns', array_merge([
                'tax_type' => 'sales',
                'period_label' => 'October 2026',
                'period_start' => '2026-10-01',
                'period_end' => '2026-10-31',
                'currency' => 'PKR',
            ], $overrides));

        $response->assertRedirect();

        return TaxReturn::latest('id')->firstOrFail();
    }
}
