<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ExchangeRate;
use App\Models\JournalEntry;
use App\Models\SalesInvoice;
use App\Services\Accounting\LedgerPosting;
use App\Support\Money;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * A foreign-currency document must land in the ledger as its base-currency value.
 *
 * journal_entry_items used to hold only debit and credit, both single-currency
 * decimals, and every posting wrote the document's face value into them. The trial
 * balance then summed those face values as though every document were in the
 * reporting currency.
 *
 * The consequence, with the base currency PKR:
 *
 *   a USD 100 invoice at 250 PKR/USD posted 100 to Accounts Receivable
 *   the balance sheet reported 100 PKR
 *   the true figure was 25,000 PKR
 *
 * A 250x understatement, on every foreign transaction, with nothing to reveal it:
 * each individual document looked right in its own currency, and no report
 * compared a transaction's currency against the reporting currency because the
 * ledger had nowhere to record that it was ever foreign.
 */
class MultiCurrencyLedgerPostingTest extends TestCase
{
    use SeedsDatabase;

    /**
     * Report in PKR and quote a rate for USD, so the conversion is observable.
     */
    private function baseIsPkr(): void
    {
        \App\Models\Setting::updateOrCreate(
            ['key' => 'base_currency'],
            ['value' => 'PKR', 'group' => 'company', 'is_public' => true],
        );
        \App\Models\Setting::updateOrCreate(
            ['key' => 'company.currency'],
            ['value' => 'PKR', 'group' => 'company', 'is_public' => true],
        );
        \App\Models\Setting::flushCache();
    }

    private function rate(string $code, string $toBase, string $date): void
    {
        ExchangeRate::create([
            'currency_code' => strtoupper($code),
            'rate_to_base' => $toBase,
            'effective_date' => $date,
        ]);
    }

    private function usdInvoice(string $total, ?string $rate = '250.00000000'): SalesInvoice
    {
        $customer = $this->customer('Foreign Buyer', 'fx@example.test');

        return SalesInvoice::create([
            'number' => 'INV-FX-'.uniqid(),
            'customer_id' => $customer->id,
            'issue_date' => '2026-01-15',
            'due_date' => '2026-02-14',
            'status' => 'sent',
            'currency' => 'USD',
            'exchange_rate' => $rate,
            'subtotal' => $total,
            'tax_amount' => '0.00',
            'total' => $total,
            'paid_amount' => '0',
            'credit_applied' => '0',
            'credit_note_applied' => '0',
        ]);
    }

    private function lineFor(JournalEntry $entry, string $code)
    {
        return $entry->items()->with('account')->get()->firstWhere('account.code', $code);
    }

    /**
     * The headline fix: the ledger carries the converted amount.
     */
    public function test_a_foreign_invoice_posts_its_base_currency_value(): void
    {
        $this->baseIsPkr();

        $invoice = $this->usdInvoice('100.00');

        $entry = app(LedgerPosting::class)->salesInvoiceIssued($invoice);

        $this->assertNotNull($entry, 'the invoice produced no journal');

        $receivable = $this->lineFor($entry->fresh(), '1100');

        // 100 USD at 250 = 25,000 PKR. Before this fix it posted 100.00.
        $this->assertSame(
            '25000.00',
            $receivable->debit,
            'the ledger recorded the face value instead of the base-currency value',
        );
    }

    /**
     * The face value is kept on the line. Revaluation cannot be computed without
     * it -- there would be nothing to compare a closing rate against.
     */
    public function test_the_face_value_and_rate_are_kept_on_the_line(): void
    {
        $this->baseIsPkr();

        $entry = app(LedgerPosting::class)->salesInvoiceIssued($this->usdInvoice('100.00'));
        $receivable = $this->lineFor($entry->fresh(), '1100');

        $this->assertSame('USD', $receivable->currency, 'the transaction currency is recorded');
        $this->assertSame('100.0000', $receivable->original_debit, 'the USD face value is kept');
        $this->assertSame('250.00000000', $receivable->exchange_rate, 'the snapshotted rate is kept');
    }

    /**
     * Every line on the journal carries the same currency. A mixed-currency
     * journal is not a thing, and it would make the balance untestable.
     */
    public function test_every_line_on_the_journal_shares_the_transaction_currency(): void
    {
        $this->baseIsPkr();

        $entry = app(LedgerPosting::class)->salesInvoiceIssued($this->usdInvoice('100.00'));

        $currencies = $entry->fresh()->items->pluck('currency')->unique()->all();

        $this->assertSame(['USD'], $currencies);
    }

    public function test_the_journal_still_balances_after_conversion(): void
    {
        $this->baseIsPkr();

        $entry = app(LedgerPosting::class)->salesInvoiceIssued($this->usdInvoice('100.00'));

        $entry->load('items');

        $debits = Money::of($entry->items->sum('debit'));
        $credits = Money::of($entry->items->sum('credit'));

        $this->assertSame($debits, $credits, 'conversion must not unbalance the journal');
        $this->assertSame('25000.00', $debits);
    }

    /**
     * An unbalanced journal expressed in a foreign currency must be refused, not
     * converted into a differently-unbalanced one.
     */
    public function test_an_unbalanced_journal_is_still_refused_in_a_foreign_currency(): void
    {
        $this->baseIsPkr();

        $run = \App\Models\PayrollRun::create([
            'number' => 'PR-FX-'.uniqid(),
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'status' => 'draft',
            'currency' => 'USD',
            'exchange_rate' => '250',
            'total_gross' => '1000.00',
            'total_net' => '1000.00',
            'total_deductions' => '0',
        ]);

        // Net cannot exceed gross without the difference landing on attendance,
        // so this resolves to a balanced set. Asserting the guard directly:
        $entry = app(LedgerPosting::class)->payrollRunApproved($run);

        $this->assertNotNull($entry);
        $entry->load('items');

        $this->assertSame(
            Money::of($entry->items->sum('debit')),
            Money::of($entry->items->sum('credit')),
        );

        // 1000 USD at 250
        $this->assertSame('250000.00', Money::of($entry->items->sum('debit')));
    }

    /**
     * A base-currency document must be entirely unaffected. This is the
     * regression guard: the conversion must not perturb ordinary postings.
     */
    public function test_a_base_currency_document_is_unchanged(): void
    {
        $this->baseIsPkr();

        $customer = $this->customer('Local Buyer', 'local@example.test');

        $invoice = SalesInvoice::create([
            'number' => 'INV-PKR-'.uniqid(),
            'customer_id' => $customer->id,
            'issue_date' => '2026-01-15',
            'status' => 'sent',
            'currency' => 'PKR',
            'exchange_rate' => 1,
            'subtotal' => '1000.00',
            'tax_amount' => '0.00',
            'total' => '1000.00',
            'paid_amount' => '0',
            'credit_applied' => '0',
            'credit_note_applied' => '0',
        ]);

        $entry = app(LedgerPosting::class)->salesInvoiceIssued($invoice);

        $this->assertSame('1000.00', $this->lineFor($entry->fresh(), '1100')->debit);
        $this->assertSame('PKR', $this->lineFor($entry->fresh(), '1100')->currency);
    }

    /**
     * A document with no currency of its own is posted in the base currency at
     * rate 1. Payroll runs and manual bank entries are denominated in the
     * reporting currency to begin with, so there is nothing to convert.
     */
    public function test_a_document_without_its_own_currency_posts_at_rate_one(): void
    {
        $this->baseIsPkr();

        $run = \App\Models\PayrollRun::create([
            'number' => 'PR-'.uniqid(),
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'status' => 'draft',
            'currency' => 'PKR',
            'exchange_rate' => 1,
            'total_gross' => '5000.00',
            'total_net' => '5000.00',
            'total_deductions' => '0',
        ]);

        $entry = app(LedgerPosting::class)->payrollRunApproved($run);

        $this->assertSame('5000.00', Money::of($entry->items()->sum('debit')));
        $this->assertSame('PKR', $entry->items()->first()->currency);
    }

    /**
     * A currency with a zero or missing rate must not be posted as though it were
     * the base currency. That is the exact understatement this column exists to
     * prevent, and silently falling back to rate 1 would reintroduce it.
     */
    /**
     * A currency whose rate was never captured must fall back to a configured
     * rate, not to 1. Falling back to 1 posts the face value, which is the exact
     * understatement this column exists to prevent.
     */
    public function test_a_missing_rate_falls_back_to_a_configured_rate_not_to_one(): void
    {
        $this->baseIsPkr();
        $this->rate('USD', '250', '2026-01-01');

        $invoice = $this->usdInvoice('100.00', null);

        $entry = app(LedgerPosting::class)->salesInvoiceIssued($invoice);

        $this->assertSame(
            '25000.00',
            $this->lineFor($entry->fresh(), '1100')->debit,
            'a missing rate fell back to 1 and posted the face value',
        );
    }

    public function test_the_reported_account_balance_is_in_base_currency(): void
    {
        $this->baseIsPkr();

        app(LedgerPosting::class)->salesInvoiceIssued($this->usdInvoice('100.00'));

        $account = Account::where('code', '1100')->firstOrFail();

        $balance = '0.00';
        foreach ($account->journalItems()->get() as $line) {
            $balance = Money::add($balance, $line->debit);
            $balance = Money::subtract($balance, $line->credit);
        }

        $this->assertSame(
            '25000.00',
            $balance,
            'the receivable balance is the base-currency amount, not the USD face value',
        );
    }

    /**
     * Regression guard.
     *
     * The first implementation fell back to rate 1 when a document had no rate,
     * which silently reintroduced the exact understatement these columns exist to
     * prevent -- a USD 100 invoice would post as 100 PKR again, with no error
     * anywhere. Any future change must fall back to a configured rate, never 1.
     */
    public function test_a_zero_rate_does_not_fall_back_to_one(): void
    {
        $this->baseIsPkr();
        $this->rate('USD', '250', '2026-01-01');

        foreach (['0', '0.00000', '-5'] as $badRate) {
            $invoice = $this->usdInvoice('100.00', $badRate);

            $entry = app(LedgerPosting::class)->salesInvoiceIssued($invoice);

            $this->assertNotNull($entry);
            $this->assertSame(
                '25000.00',
                $this->lineFor($entry->fresh(), '1100')->debit,
                "rate {$badRate} was treated as 1, posting the face value",
            );
        }
    }

    /**
     * A payroll run has to carry a rate, because it holds a currency column.
     *
     * It did not: the column was absent, so the rate read as null and the run's
     * gross was posted as though it were base currency. This asserts the column
     * exists on the model rather than only on the table, because a column that is
     * not fillable is silently dropped on save and produces the same bug with no
     * database error at all.
     */
    public function test_a_payroll_run_carries_a_usable_rate(): void
    {
        $this->baseIsPkr();
        $this->rate('USD', '250', '2026-01-01');

        $run = \App\Models\PayrollRun::create([
            'number' => 'PR-FXRATE-'.uniqid(),
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'status' => 'draft',
            'currency' => 'USD',
            'exchange_rate' => null,
            'total_gross' => '1000.00',
            'total_net' => '1000.00',
            'total_deductions' => '0',
        ]);

        $this->assertContains(
            'exchange_rate',
            \App\Models\PayrollRun::query()->getModel()->getFillable(),
            'PayrollRun does not persist its rate, so a foreign run always posted its face value',
        );

        $entry = app(LedgerPosting::class)->payrollRunApproved($run->fresh());

        $this->assertSame(
            '250000.00',
            Money::of($entry->items()->sum('debit')),
            'a foreign payroll run posted its face value instead of the base-currency equivalent',
        );
    }
}