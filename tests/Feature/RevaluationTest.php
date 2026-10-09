<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ExchangeRate;
use App\Models\FiscalPeriod;
use App\Models\SalesInvoice;
use App\Services\Accounting\LedgerPosting;
use App\Services\Accounting\RevaluationService;
use App\Support\Money;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * Revaluing open foreign balances at a closing rate.
 *
 * A document is posted at the rate it was raised at, and that rate is history.
 * But a balance still open at period end is measured at the closing rate, and
 * where the two differ the balance sheet carries a figure in the reporting
 * currency that no longer describes the underlying foreign money.
 *
 * The rules that matter, and that these tests pin:
 *
 *  - only the *difference* is posted; transaction rates are never rewritten
 *  - only monetary asset and liability balances; P&L is not revalued
 *  - a liability that translates to less is a gain, not a loss
 *  - a missing closing rate skips the adjustment rather than assuming parity
 *  - an open period is refused, because its balances are still moving
 */
class RevaluationTest extends TestCase
{
    use SeedsDatabase;

    private function baseIsPkr(): void
    {
        foreach (['base_currency', 'company.currency'] as $key) {
            \App\Models\Setting::updateOrCreate(
                ['key' => $key],
                ['value' => 'PKR', 'group' => 'company', 'is_public' => true],
            );
        }
        \App\Models\Setting::flushCache();
    }

    private function rate(string $rate, string $date = '2026-01-01'): void
    {
        ExchangeRate::create([
            'currency_code' => 'USD',
            'rate_to_base' => $rate,
            'effective_date' => $date,
        ]);
    }

    private function january(): FiscalPeriod
    {
        return app(\App\Services\Accounting\FiscalPeriodService::class)->create([
            'name' => 'January 2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-31',
        ]);
    }

    /**
     * A USD receivable raised at the given rate.
     */
    private function usdReceivable(string $amount, string $rate): SalesInvoice
    {
        $customer = $this->customer('FX Customer', 'fx'.uniqid().'@example.test');

        $invoice = SalesInvoice::create([
            'number' => 'INV-FX-'.uniqid(),
            'customer_id' => $customer->id,
            'issue_date' => '2026-01-15',
            'status' => 'sent',
            'currency' => 'USD',
            'exchange_rate' => $rate,
            'subtotal' => $amount,
            'tax_amount' => '0.00',
            'total' => $amount,
            'paid_amount' => '0',
            'credit_applied' => '0',
            'credit_note_applied' => '0',
        ]);

        app(LedgerPosting::class)->salesInvoiceIssued($invoice);

        return $invoice;
    }

    private function receivableBalance(): string
    {
        $account = Account::where('code', '1100')->firstOrFail();

        $balance = '0.00';
        foreach ($account->journalItems()->get() as $line) {
            $balance = Money::add($balance, $line->debit);
            $balance = Money::subtract($balance, $line->credit);
        }

        return $balance;
    }

    /**
     * A stronger dollar raises the value of a foreign receivable: an unrealised
     * gain.
     */
    public function test_a_strengthening_currency_produces_an_unrealised_gain(): void
    {
        $this->baseIsPkr();
        $this->rate('250', '2026-01-10');
        $this->usdReceivable('1000.00', '250');

        // Carried at 250 => 250,000 PKR.
        $this->assertSame('250000.00', $this->receivableBalance());

        // The dollar strengthens to 260 by month end.
        $this->rate('260', '2026-01-31');

        $entry = app(RevaluationService::class)->post($this->january());

        $this->assertNotNull($entry, 'a currency that moved produced no revaluation');

        // 1000 USD at 260 = 260,000, so the receivable rises by 10,000.
        $this->assertSame(
            '260000.00',
            $this->receivableBalance(),
            'the receivable was not restated to the closing rate',
        );
    }

    public function test_the_gain_is_credited_to_the_gain_account(): void
    {
        $this->baseIsPkr();
        $this->rate('250', '2026-01-10');
        $this->usdReceivable('1000.00', '250');
        $this->rate('260', '2026-01-31');

        $entry = app(RevaluationService::class)->post($this->january());
        $entry->load('items.account');

        $gain = $entry->items->firstWhere(fn ($i) => $i->account->code === '6600');

        $this->assertNotNull($gain, 'an unrealised gain must be separated from trading results');
        $this->assertSame('10000.00', $gain->credit);
    }

    /**
     * A weakening currency is a loss.
     */
    public function test_a_weakening_currency_produces_an_unrealised_loss(): void
    {
        $this->baseIsPkr();
        $this->rate('250', '2026-01-10');
        $this->usdReceivable('1000.00', '250');
        $this->rate('240', '2026-01-31');

        $entry = app(RevaluationService::class)->post($this->january());
        $entry->load('items.account');

        $loss = $entry->items->firstWhere(fn ($i) => $i->account->code === '6610');

        $this->assertNotNull($loss);
        $this->assertSame('10000.00', $loss->debit);
        $this->assertSame('240000.00', $this->receivableBalance());
    }

    /**
     * A liability that translates to less is a gain, not a loss.
     *
     * This is the sign that a naive implementation gets wrong: it treats a
     * negative difference as a loss regardless of which side of the balance sheet
     * the account sits on, which understates a falling payable.
     */
    public function test_a_falling_liability_is_a_gain_not_a_loss(): void
    {
        $this->baseIsPkr();

        $bill = \App\Models\PurchaseInvoice::create([
            'number' => 'PIN-FX-'.uniqid(),
            'supplier_id' => $this->supplier('FX Supplier')->id,
            'issue_date' => '2026-01-10',
            'status' => 'sent',
            'currency' => 'USD',
            'exchange_rate' => '250',
            'subtotal' => '1000.00',
            'tax_amount' => '0.00',
            'total' => '1000.00',
            'paid_amount' => '0',
        ]);

        app(LedgerPosting::class)->purchaseBillIssued($bill);

        // USD weakens, so the payable is worth less: a gain.
        $this->rate('240', '2026-01-31');

        $entry = app(RevaluationService::class)->post($this->january());
        $entry->load('items.account');

        $gain = $entry->items->firstWhere(fn ($i) => $i->account->code === '6600');

        $this->assertNotNull($gain, 'a falling payable was booked as a loss');
        $this->assertSame('10000.00', $gain->credit);
        $this->assertNull(
            $entry->items->firstWhere(fn ($i) => $i->account->code === '6610'),
            'a falling payable produced a loss entry',
        );
    }

    /**
     * The original transaction must not be rewritten. Revaluation adjusts the
     * closing position; it does not restate history.
     */
    public function test_the_original_transaction_rate_is_never_rewritten(): void
    {
        $this->baseIsPkr();
        $this->rate('250', '2026-01-10');
        $this->usdReceivable('1000.00', '250');
        $this->rate('260', '2026-01-31');

        app(RevaluationService::class)->post($this->january());

        $original = Account::where('code', '1100')->firstOrFail()->journalItems()
            ->where('currency', 'USD')
            ->where('original_debit', '>', 0)
            ->first();

        $this->assertSame(
            '250.00000000',
            $original->exchange_rate,
            'the transaction rate was rewritten to the closing rate',
        );
        $this->assertSame('1000.0000', $original->original_debit, 'the USD face value was rewritten');
    }

    /**
     * Only the difference is posted, not the whole closing balance. Posting the
     * full amount would debit the receivable a second time in its entirety.
     */
    public function test_only_the_difference_is_posted(): void
    {
        $this->baseIsPkr();
        $this->rate('250', '2026-01-10');
        $this->usdReceivable('1000.00', '250');
        $this->rate('260', '2026-01-31');

        $entry = app(RevaluationService::class)->post($this->january());
        $entry->load('items');

        $this->assertSame('10000.00', Money::of($entry->items->sum('debit')));
        $this->assertSame('10000.00', Money::of($entry->items->sum('credit')));
    }

    /**
     * An unchanged rate means nothing to adjust. Posting a zero journal on every
     * close would bury the real entries in noise.
     */
    public function test_an_unchanged_rate_produces_no_entry(): void
    {
        $this->baseIsPkr();
        $this->rate('250', '2026-01-10');
        $this->usdReceivable('1000.00', '250');
        $this->rate('250', '2026-01-31');

        $this->assertNull(
            app(RevaluationService::class)->post($this->january()),
            'a revaluation was posted with no rate movement',
        );
    }

    /**
     * A missing closing rate is a decision for the user, not something to guess.
     * Assuming parity would value every foreign balance at 1:1.
     */
    public function test_a_missing_closing_rate_skips_rather_than_assuming_parity(): void
    {
        $this->baseIsPkr();
        $this->rate('250', '2026-01-10');
        $this->usdReceivable('1000.00', '250');

        // No rate on or before 2026-01-31 exists: the only one is in January but
        // later than the period end is impossible, so use a rate only after close.
        ExchangeRate::query()->delete();
        $this->rate('260', '2026-02-15');

        $this->assertNull(
            app(RevaluationService::class)->post($this->january()),
            'a rate dated after the period end was used as the closing rate',
        );
    }

    /**
     * A revaluation is the closing entry, so it belongs inside the period -- and a
     * closed period refuses every entry dated on or before its lock date.
     *
     * That makes "revalue the period after closing it" impossible by
     * construction, which is the whole reason closeWithRevaluation() exists.
     */
    public function test_a_closed_period_can_no_longer_be_revalued(): void
    {
        $this->baseIsPkr();
        $this->rate('250', '2026-01-10');
        $this->usdReceivable('1000.00', '250');
        $this->rate('260', '2026-01-31');

        $period = $this->january();
        app(\App\Services\Accounting\FiscalPeriodService::class)->close($period);

        $this->expectException(\RuntimeException::class);
        app(RevaluationService::class)->post($period->fresh());
    }

    /**
     * The supported sequence: revalue, then close. Both happen, in that order.
     */
    public function test_closing_with_revaluation_posts_the_adjustment_and_locks_the_period(): void
    {
        $this->baseIsPkr();
        $this->rate('250', '2026-01-10');
        $this->usdReceivable('1000.00', '250');
        $this->rate('260', '2026-01-31');

        $period = $this->january();

        $entry = app(RevaluationService::class)->closeWithRevaluation($period, null, 'Month end');

        $this->assertNotNull($entry, 'closing with revaluation produced no adjustment');
        $this->assertSame('closed', $period->fresh()->status);
        $this->assertSame('260000.00', $this->receivableBalance());

        // And the period is genuinely shut afterwards.
        $this->expectException(\RuntimeException::class);
        app(RevaluationService::class)->post($period->fresh());
    }

    /**
     * A repeat close must not double the adjustment.
     */
    public function test_a_period_cannot_be_revalued_twice(): void
    {
        $this->baseIsPkr();
        $this->rate('250', '2026-01-10');
        $this->usdReceivable('1000.00', '250');
        $this->rate('260', '2026-01-31');

        $period = $this->january();
        $service = app(RevaluationService::class);

        $this->assertNotNull($service->post($period));
        $this->assertNull($service->post($period), 'a repeat revaluation doubled the adjustment');
        $this->assertSame('260000.00', $this->receivableBalance());
    }

    /**
     * The adjustment must land in the period being closed, so it is subject to
     * the same lock that governs everything else in it.
     */
    public function test_the_adjustment_is_stamped_with_the_period(): void
    {
        $this->baseIsPkr();
        $this->rate('250', '2026-01-10');
        $this->usdReceivable('1000.00', '250');
        $this->rate('260', '2026-01-31');

        $period = $this->january();
        $entry = app(RevaluationService::class)->post($period);

        $this->assertSame($period->id, $entry->fiscal_period_id);
    }

    public function test_the_revaluation_journal_balances(): void
    {
        $this->baseIsPkr();
        $this->rate('250', '2026-01-10');
        $this->usdReceivable('1000.00', '250');
        $this->rate('260', '2026-01-31');

        $entry = app(RevaluationService::class)->post($this->january());
        $entry->load('items');

        $this->assertSame(
            Money::of($entry->items->sum('debit')),
            Money::of($entry->items->sum('credit')),
        );
    }
}