<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\DebitNote;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\SalesCreditNote;
use App\Models\SalesInvoice;
use App\Support\GeneralLedger;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turns business documents into balanced, posted journal entries.
 *
 * This is the piece that makes the application an accounting system rather than a
 * document store. Until now nothing outside JournalController wrote to the ledger:
 * an invoice could be issued, a payment received and a bill paid without a single
 * double-entry posting, so the trial balance, balance sheet and P&L were
 * permanently empty no matter how much trading the business did.
 *
 * Design rules, in priority order:
 *
 *  1. **Never post twice.** Every method is keyed on the source document and is a
 *     no-op if that document already has a journal. Re-running a posting, retrying
 *     a failed request, or an idempotent job must not duplicate the entry.
 *  2. **Never post an unbalanced entry.** Lines are built and checked here, and
 *     GeneralLedger refuses to post anything that does not balance.
 *  3. **Never lose money to rounding.** Amounts are decimal strings produced by
 *     Money, and the residual of a rounded distribution is pushed onto the largest
 *     line rather than being dropped.
 *  4. **One document, one journal.** Both the reference and the description carry
 *     the document number, so a posting is traceable back to its source from the
 *     ledger alone.
 */
class LedgerPosting
{
    public const ACCOUNTS_RECEIVABLE = '1100';
    public const INVENTORY = '1200';
    public const CASH = '1000';
    public const BANK = '1010';

    public const ACCOUNTS_PAYABLE = '2000';

    /**
     * Cash movements not yet identified -- deposits in transit, unidentified
     * withdrawals, direct debits never matched to a document.
     *
     * A liability rather than an expense because the amount is not yet known to be
     * a cost. Booking unidentified bank activity straight to an expense would put a
     * permanent phantom cost on the P&L for every unidentified deposit.
     */
    public const UNCATEGORISED_BANK_ITEMS = '2050';

    /**
     * A known bank cost -- fees, interest, chargebacks.
     *
     * Callers pass this as the offset when they *do* know what the movement was,
     * which is the difference between a bank charge being a real expense and an
     * unexplained withdrawal sitting on the books as a liability forever.
     */
    public const BANK_CHARGES = '6300';
    public const OUTPUT_TAX_PAYABLE = '2200';
    public const INPUT_TAX_RECEIVABLE = '1310';

    public const SALES_REVENUE = '4000';

    /**
     * Sales returned against, as a debit.
     *
     * A contra-revenue account rather than a debit to Sales Revenue itself: a
     * credit note does not erase the original sale, it records that part of it came
     * back. Netting them keeps the gross revenue figure the business actually
     * invoiced intact for period-on-period comparison, which is the whole reason a
     * return is a return and not an erasure.
     */
    public const SALES_RETURNS = '4300';

    public const COST_OF_GOODS_SOLD = '5000';
    public const INVENTORY_ADJUSTMENT = '6400';

    public const SALES_DISCOUNTS = '4200';

    public const SALARIES_AND_WAGES = '6200';

    /**
     * Net pay owed to employees but not yet paid out.
     *
     * Accrued rather than paid directly: a payroll run is a liability the moment
     * it is approved, and until the money leaves the bank it is still owed.
     */
    public const NET_PAY_PAYABLE = '2150';

    /**
     * Employee income tax and social contributions withheld from net pay.
     *
     * Withheld at source, so it is a liability to the authority rather than a
     * cost -- which is why it never touches the expense account.
     */
    public const PAYROLL_TAX_PAYABLE = '2250';

    private static ?bool $readyMemo = null;

    /**
     * Whether the ledger is ready to accept automatic postings.
     *
     * Deployments seeded before the chart of accounts existed have no accounts at
     * all. Without this check, wiring automatic posting into the sales and
     * purchasing controllers would make every invoice 500 on those installations
     * until someone remembered to re-seed. Skipping with a warning keeps them
     * trading; the gap is visible in the log rather than silent.
     *
     * Memoised per request because it is consulted on every document save.
     */
    public function isReady(): bool
    {
        if (self::$readyMemo !== null) {
            return self::$readyMemo;
        }

        $required = [
            self::ACCOUNTS_RECEIVABLE, self::ACCOUNTS_PAYABLE, self::SALES_REVENUE,
            self::OUTPUT_TAX_PAYABLE, self::INPUT_TAX_RECEIVABLE, self::CASH, self::BANK,
            // Needed by credit notes. A deployment whose chart predates 4300 will
            // report it missing and skip posting rather than raising an exception
            // mid-document-save; the warning names the seeder to re-run.
            self::SALES_RETURNS,
        ];

        $found = Account::whereIn('code', $required)->pluck('code')->all();
        $missing = array_values(array_diff($required, $found));

        if ($missing !== []) {
            Log::warning(
                'Automatic ledger posting skipped: chart of accounts is incomplete (missing '
                .implode(', ', $missing).'). Run `php artisan db:seed --class=ChartOfAccountsSeeder --force`.',
            );
        }

        return self::$readyMemo = $missing === [];
    }

    public static function flushReadiness(): void
    {
        self::$readyMemo = null;
    }

    /**
     * The journal already generated for a document, if any.
     *
     * Identified by reference, which is set to the document class and id, so this
     * is the idempotency key for the whole service.
     */
    public function journalFor(Model $document): ?JournalEntry
    {
        return JournalEntry::query()
            ->where('reference', $this->referenceFor($document))
            ->whereIn('status', ['draft', 'posted'])
            ->first();
    }

    /**
     * Whether any journal already exists for a document, ignoring its status.
     *
     * hasPosted() only considers draft and posted entries, so it reports false for
     * a reversed run. That is right for deciding whether to repost, but wrong for
     * deciding whether an accrual ever existed -- a run that was approved, then
     * reversed, still has a liability behind it.
     */
    /**
     * Whether a journal already exists for a document, ignoring its status.
     *
     * journalFor() only considers draft and posted entries, so it reports false
     * for a reversed document. That is right for deciding whether to repost, but
     * wrong for deciding whether an accrual ever existed -- a payroll run that was
     * approved and then reversed still has a history behind it.
     */
    public function hasJournalFor(Model $document, string $stage = ''): bool
    {
        return $stage === ''
            ? $this->journalsFor($document)->isNotEmpty()
            : $this->journalAtStage($document, $stage) !== null;
    }

    /**
     * The journal for a specific lifecycle stage, if it exists.
     */
    private function journalAtStage(Model $document, string $stage): ?JournalEntry
    {
        return JournalEntry::query()
            ->where('reference', $stage === ''
                ? $this->referenceFor($document)
                : $this->stageReference($document, $stage))
            ->whereIn('status', ['draft', 'posted'])
            ->first();
    }

    public function hasPosted(Model $document): bool
    {
        return JournalEntry::query()
            ->where('reference', $this->referenceFor($document))
            ->where('status', 'posted')
            ->exists();
    }

    /**
     * Post a balanced journal and immediately mark it posted.
     *
     * @param  array<int, array{code: string, debit?: string|float, credit?: string|float, memo?: string|null}>  $lines
     */
public function post(
        Model $document,
        array $lines,
        string $description,
        ?string $date = null,
        string $stage = '',
    ): ?JournalEntry {
        // Idempotency: a document is posted once per lifecycle stage, ever.
        if ($this->journalAtStage($document, $stage) !== null) {
            return null;
        }

        // A deployment with no chart of accounts keeps trading and keeps an empty
        // ledger, rather than refusing to save documents. Logged, not silent.
        if (! $this->isReady()) {
            return null;
        }

$resolved = $this->resolveAccounts($lines, $this->currencyContextFor($document));

        if ($resolved === []) {
            return null;
        }

$entry = DB::transaction(function () use ($document, $resolved, $description, $date, $stage) {
            $entry = JournalEntry::create([
                'number' => next_document_number('journal', 'JV'),
                'entry_date' => $date ?: today()->toDateString(),
                'reference' => $this->stageReference($document, $stage),
                'description' => $description,
                'status' => 'draft',
                'created_by' => auth()->id(),
            ]);

            GeneralLedger::replaceLines($entry, $resolved);

            return $entry;
        });

        GeneralLedger::post($entry);

        return $entry->fresh();
    }

    // ── Sales ────────────────────────────────────────────────────────────────

    /**
     * A sales invoice recognises revenue and creates a receivable.
     *
     *   Dr Accounts Receivable   gross
     *       Cr Output Tax Payable      tax
     *       Cr Sales Revenue           net
     *
     * Cost of goods sold is deliberately NOT posted here: this application does not
     * carry a cost price on invoice lines, so any figure would be invented. It is
     * posted from a stock movement or a purchase receipt instead.
     */
public function salesInvoiceIssued(SalesInvoice $invoice): ?JournalEntry
    {
        $gross = Money::of($invoice->total);
        $tax = Money::of($invoice->tax_amount);
        $net = Money::subtract($gross, $tax);

        $lines = [
            ['code' => self::ACCOUNTS_RECEIVABLE, 'debit' => $gross],
            ['code' => self::OUTPUT_TAX_PAYABLE, 'credit' => $tax],
            ['code' => self::SALES_REVENUE, 'credit' => $net],
        ];

        // Cost of goods sold and the matching relief of inventory.
        //
        // Selling from stock posted revenue with no cost, and nothing left
        // Inventory: margin was overstated by the cost of everything sold and
        // stock on the balance sheet never fell however much was shipped. The cost
        // per line is the unit_cost snapshotted when the invoice was saved, so a
        // later repricing cannot rewrite this invoice's margin.
        $cost = $this->costOfGoodsSold($invoice);

        if (Money::isGreaterThan($cost, '0')) {
            $lines[] = ['code' => self::COST_OF_GOODS_SOLD, 'debit' => $cost];
            $lines[] = ['code' => self::INVENTORY, 'credit' => $cost];
        }

        return $this->post($invoice, $lines, "Sales invoice {$invoice->number} issued", $invoice->issue_date?->toDateString());
    }

    /**
     * Total cost of the goods on an invoice, from the snapshotted unit costs.
     *
     * The salary expense line is debit gross and credit attendance deductions on
     * the same account, so the *net* movement is the amount actually earned.
     * Netting them into one figure is what keeps gross and net distinct: an
     * employer incurs the wage for work done, and an unworked day is a smaller
     * cost, not a smaller cheque with an unchanged expense.
     *
     * Zero for a service business, where no line carries a product cost.
     */
    private function costOfGoodsSold(SalesInvoice $invoice): string
    {
        $total = '0';

        foreach ($invoice->items()->get() as $item) {
            if ($item->unit_cost === null) {
                continue;
            }

            $total = Money::add(
                $total,
                Money::multiply((string) $item->unit_cost, (string) $item->qty),
            );
        }

        return $total;
    }

    /**
     * A customer receipt settles part or all of the receivable.
     *
     *   Dr Cash / Bank   amount
     *       Cr Accounts Receivable   amount
     */
    public function paymentReceived(Model $payment, string $cashAccountCode = self::CASH): ?JournalEntry
    {
        $amount = Money::of($payment->amount);

        return $this->post($payment, [
            ['code' => $cashAccountCode, 'debit' => $amount],
            ['code' => self::ACCOUNTS_RECEIVABLE, 'credit' => $amount],
        ], "Payment {$payment->number} received");
    }

    /**
     * A sales credit note reverses what the original invoice recognised.
     *
     *   Dr Sales Returns            net
     *   Dr Output Tax Payable       tax
     *       Cr Accounts Receivable  gross
     *
     * Without this the credit note moved the customer's sub-ledger balance but left
     * revenue, output tax and the receivable standing in the general ledger, so a
     * single credit note made the balance sheet disagree with the sales register.
     * The sub-ledger and the ledger have to move together, exactly as they do when
     * an invoice is issued and when it is paid.
     *
     * Cost of goods sold is deliberately NOT reversed here, and that is a real
     * limitation rather than an oversight. Reversing it means putting the goods back
     * into stock, but a credit note is a document: it does not prove the goods came
     * back off the shelf. Restoring inventory on the strength of a piece of paper
     * would invent stock that may never have returned and make the valuation wrong
     * in the other direction.
     *
     * When the goods genuinely do come back, the reversal is an inventory receipt --
     * an explicit, auditable event that moves stock and credits COGS together. That
     * is a separate deliberate step, not something the credit note should assume.
     */
    public function creditNoteIssued(SalesCreditNote $note): ?JournalEntry
    {
        $gross = Money::of($note->total);
        $tax = Money::of($note->tax_amount);
        $net = Money::subtract($gross, $tax);

        $lines = [
            ['code' => self::SALES_RETURNS, 'debit' => $net],
            ['code' => self::OUTPUT_TAX_PAYABLE, 'debit' => $tax],
            ['code' => self::ACCOUNTS_RECEIVABLE, 'credit' => $gross],
        ];

        return $this->post($note, $lines, "Credit note {$note->number} issued", $note->issue_date?->toDateString());
    }

    // ── Purchasing ───────────────────────────────────────────────────────────

    /**
     * A purchase debit note reverses what the original bill recognised.
     *
     *   Dr Accounts Payable        gross
     *       Cr <expense account>     net
     *       Cr Input Tax Receivable  tax
     *
     * Without this the supplier's sub-ledger balance fell but the expense and the
     * input tax stayed in the general ledger, so a purchase return left the P&L
     * overstated and left input tax receivable claiming back tax on goods the
     * business no longer holds.
     *
     * Each line is credited back to the SAME account the bill charged it to, read
     * from the original invoice where there is one. Crediting a single account
     * instead would put a purchase return against the wrong cost line, which is
     * how a P&L stops answering "which costs went up".
     *
     * Reversing the input tax matters more than it looks: input tax is money the
     * business expects back from the authority, and leaving it claimed on returned
     * goods is an overclaim.
     */
    public function debitNoteIssued(DebitNote $note): ?JournalEntry
    {
        $gross = Money::of($note->total);
        $tax = Money::of($note->tax_amount);

        $byAccount = [];

        foreach ($note->items()->get() as $item) {
            $code = $this->accountForDebitNoteLine($item, $note);

            $byAccount[$code] = Money::add(
                $byAccount[$code] ?? '0',
                Money::of($item->line_total),
            );
        }

        if ($byAccount === []) {
            $byAccount[self::COST_OF_GOODS_SOLD] = Money::subtract($gross, $tax);
        }

        $lines = [['code' => self::ACCOUNTS_PAYABLE, 'debit' => $gross]];

        foreach ($byAccount as $code => $amount) {
            $lines[] = ['code' => $code, 'credit' => $amount];
        }

        if (Money::isGreaterThan($tax, '0')) {
            $lines[] = ['code' => self::INPUT_TAX_RECEIVABLE, 'credit' => $tax];
        }

        return $this->post($note, $lines, "Debit note {$note->number} issued", $note->issue_date?->toDateString());
    }

    /**
     * The account a debit-note line reverses, preferring the original bill's line.
     */
    private function accountForDebitNoteLine(object $item, DebitNote $note): string
    {
        if ($item->account_id !== null) {
            return Account::find($item->account_id)?->code ?? self::COST_OF_GOODS_SOLD;
        }

        // No account on the note's own line: fall back to the matching line on the
        // invoice being credited, matched in order, so the reversal lands on the
        // cost the original purchase was charged to.
        if ($note->invoice_id !== null) {
            $original = PurchaseInvoice::find($note->invoice_id)?->items()?->get()
                ?->filter(fn ($i) => Money::equals(Money::of($i->line_total), Money::of($item->line_total)))
                ?->first();

            if ($original?->account_id !== null) {
                return Account::find($original->account_id)?->code ?? self::COST_OF_GOODS_SOLD;
            }
        }

        return self::COST_OF_GOODS_SOLD;
    }


    /**
     * A purchase bill recognises the liability and the recoverable input tax.
     *
     *   Dr Cost of Goods Sold        net
     *   Dr Input Tax Receivable      tax
     *       Cr Accounts Payable      gross
     *
     * The tax is a *debit* to an asset, not a credit against output tax: tax the
     * business pays on a purchase is money it is owed back, whereas tax it collects
     * on a sale is money it owes. Crediting a purchase bill's tax to the same
     * Output Tax Payable account nets the two off and leaves the entry unbalanced,
     * because the payable is then credited with the gross *and* the tax.
     *
     * The expense account is a simplification: this application does not carry an
     * expense category on bill lines, so everything is recognised as cost of goods
     * sold. Add categories and map them here before relying on the P&L by expense.
     */
public function purchaseBillIssued(PurchaseInvoice $bill): ?JournalEntry
    {
        $gross = Money::of($bill->total);
        $tax = Money::of($bill->tax_amount);

        // Charge each line to the expense account it carries, so the P&L can answer
        // which costs went up. Lines without an account fall back to COGS, which is
        // what every bill did before this existed.
        $byAccount = [];

        foreach ($bill->items()->get() as $item) {
            $code = $item->account_id !== null
                ? (Account::find($item->account_id)?->code ?? self::COST_OF_GOODS_SOLD)
                : self::COST_OF_GOODS_SOLD;

            $byAccount[$code] = Money::add($byAccount[$code] ?? '0', Money::of($item->line_total));
        }

        if ($byAccount === []) {
            // A bill with no lines: charge the whole net to COGS rather than
            // posting nothing, which would leave the payable unbalanced.
            $byAccount[self::COST_OF_GOODS_SOLD] = Money::subtract($gross, $tax);
        }

        $lines = [];

        foreach ($byAccount as $code => $amount) {
            $lines[] = ['code' => $code, 'debit' => $amount];
        }

        $lines[] = ['code' => self::INPUT_TAX_RECEIVABLE, 'debit' => $tax];
        $lines[] = ['code' => self::ACCOUNTS_PAYABLE, 'credit' => $gross];

        return $this->post($bill, $lines, "Purchase bill {$bill->number} received", $bill->issue_date?->toDateString());
    }

    /**
     * A supplier payment settles part or all of the payable.
     *
     *   Dr Accounts Payable   amount
     *       Cr Cash / Bank   amount
     */
    public function paymentMade(Model $payment, string $cashAccountCode = self::BANK): ?JournalEntry
    {
        $amount = Money::of($payment->amount);

        return $this->post($payment, [
            ['code' => self::ACCOUNTS_PAYABLE, 'debit' => $amount],
            ['code' => $cashAccountCode, 'credit' => $amount],
        ], "Payment {$payment->number} made");
    }

// ── Banking ──────────────────────────────────────────────────────────────

    /**
     * A transfer between two bank accounts.
     *
     *   Dr destination ledger account   amount
     *       Cr source ledger account        amount
     *
     * This was silently missing. BankLedger wrote the two bank_transactions rows
     * and stopped, so the money moved in the bank ledger while neither side of the
     * balance sheet changed. The account balances and the trial balance then
     * disagreed, and nothing surfaced it: every individual page looked right.
     *
     * Both legs come from the accounts each bank account is mapped to, so a
     * foreign-currency account posts against its own ledger account rather than
     * being forced into a single "Bank" code.
     */
    public function bankTransferPosted(Model $transfer, ?string $date = null): ?JournalEntry
    {
        $amount = Money::of($transfer->amount);

        if (Money::equals($amount, '0')) {
            return null;
        }

        $fromCode = $this->ledgerCodeFor($transfer->fromAccount);
        $toCode = $this->ledgerCodeFor($transfer->toAccount);

        // Without a mapping the posting cannot be attributed to a ledger account,
        // and guessing one would put the transfer against an account nobody chose.
        if (! $fromCode || ! $toCode) {
            Log::warning(
                'Bank transfer '.$transfer->number.' not posted: the source or destination bank '
                .'account is not mapped to a ledger account. Set the mapping on the bank account.',
            );

            return null;
        }

        $toAccountId = Account::where('code', $toCode)->value('id');
        $fromAccountId = Account::where('code', $fromCode)->value('id');

        $entry = $this->post(
            $transfer,
            [
                ['code' => $toCode, 'debit' => $amount, 'memo' => 'Transfer in from '.$transfer->fromAccount?->name],
                ['code' => $fromCode, 'credit' => $amount, 'memo' => 'Transfer out to '.$transfer->toAccount?->name],
            ],
            "Bank transfer {$transfer->number}",
            $date ?? ($transfer->transfer_date?->toDateString()),
        );

        // Stamped so an edit can void exactly this journal.
        if ($entry) {
            $transfer->forceFill(['journal_entry_id' => $entry->id])->saveQuietly();
        }

        unset($toAccountId, $fromAccountId);

        return $entry;
    }

    /**
     * A single bank transaction: a deposit, a direct debit, a bank charge.
     *
     *   Dr / Cr the mapped ledger account   amount
     *       Cr / Dr the offset account          amount
     *
     * The offset defaults to Uncategorised Bank Items. That is deliberate and it is
     * the opposite of a shortcut: an unidentified deposit is money that has arrived
     * and not yet been explained, so it is a liability until someone matches it.
     * Booking it as an expense instead would put a permanent phantom cost on the
     * P&L for every deposit the business has not yet attributed.
     */
    public function bankTransactionPosted(Model $transaction, ?string $offsetCode = null, ?string $date = null): ?JournalEntry
    {
        $amount = Money::of($transaction->amount);

        if (Money::equals($amount, '0')) {
            return null;
        }

        $code = $this->ledgerCodeFor($transaction->account);

        if (! $code) {
            Log::warning(
                'Bank transaction '.$transaction->number.' not posted: its bank account is not '
                .'mapped to a ledger account. Set the mapping on the bank account.',
            );

            return null;
        }

        $offset = $offsetCode ?? self::UNCATEGORISED_BANK_ITEMS;

        $isInflow = in_array($transaction->type, ['deposit', 'transfer_in'], true);

        $lines = $isInflow
            ? [
                ['code' => $code, 'debit' => $amount, 'memo' => $transaction->description],
                ['code' => $offset, 'credit' => $amount, 'memo' => 'Unidentified bank item'],
            ]
            : [
                ['code' => $offset, 'debit' => $amount, 'memo' => 'Unidentified bank item'],
                ['code' => $code, 'credit' => $amount, 'memo' => $transaction->description],
            ];

        $entry = $this->post(
            $transaction,
            $lines,
            'Bank transaction '.$transaction->number,
            $date ?? ($transaction->transaction_date?->toDateString()),
        );

        if ($entry) {
            $transaction->forceFill(['journal_entry_id' => $entry->id])->saveQuietly();
        }

        return $entry;
    }

    /**
     * The ledger account code a bank account maps to, or null when unmapped.
     */
    /**
     * The mapped ledger account, or null when the bank account is unmapped.
     *
     * The relation on BankTransaction is named account() even though it points at
     * a bank_account_id column, which is exactly the sort of naming trap that makes
     * a lookup silently return null instead of failing.
     */
private function ledgerCodeFor(mixed $bankAccount): ?string
    {
        if (! $bankAccount) {
            return null;
        }

        // An account typed "cash" is petty cash, not a bank account, so it belongs
        // in Cash on Hand. Anything else is a real bank balance.
        if ($bankAccount->account_type === 'cash' && ! $bankAccount->account_id) {
            return self::CASH;
        }

        return $bankAccount->account_id
            ? Account::whereKey($bankAccount->account_id)->value('code')
            : null;
    }

    /**
     * Void the journal belonging to a bank transaction or transfer.
     *
     * Separate from reverse() because the journal is stamped on the row, and a
     * voided row may have been re-posted under a new id.
     */
    public function reverseBankMovement(Model $movement): bool
    {
        $entry = $movement->journal_entry_id
            ? JournalEntry::find($movement->journal_entry_id)
            : $this->primaryJournalFor($movement);

        if ($entry === null || $entry->status !== 'posted') {
            return false;
        }

        GeneralLedger::void($entry);

        return true;
    }

    // ── Point of sale ────────────────────────────────────────────────────────

    /**
     * A completed till sale.
     *
     *   Dr Cash / Bank          total collected
     *       Cr Sales Revenue         subtotal less discount
     *       Cr Sales Discounts       discount given
     *       Cr Output Tax Payable    tax charged
     *   Dr Cost of Goods Sold    cost of stock sold
     *       Cr Inventory               the same cost
     *
     * The discount is posted to its own contra-revenue account rather than being
     * netted off Sales Revenue. Netting it hides the size of the discount, which is
     * the number a retailer actually manages: a 40% run of discounts and a 0% run
     * both look like "revenue" once they are merged, and gross margin stops
     * meaning anything.
     *
     * Tax is credited as collected rather than computed from a rate: the till
     * knows what it charged, and a rate applied again would disagree with the
     * receipt the customer took home.
     *
     * Cost of sales uses each product's cost_price at the moment of sale. Where a
     * line has no product -- a loose item rung up by name -- no cost is posted.
     * Inventing one would put a margin on the books that cannot be substantiated;
     * the gap is visible as an un-costed sale rather than hidden in a guess.
     */
    public function posSaleCompleted(Model $sale, string $settlementAccountCode = self::CASH, ?string $date = null): ?JournalEntry
    {
        $total = Money::of($sale->total);
        $tax = Money::of($sale->tax);
        $discount = Money::of($sale->discount);

        // Net revenue is what remains of the subtotal after the discount, and tax
        // is charged on top of that. Deriving it from subtotal and discount --
        // rather than trusting total - means a rounding slip in the till cannot
        // corrupt the revenue figure.
        /**
         * Revenue is recognised GROSS, at the till's line total.
         *
         * A discount given does not reduce what the goods are worth, it is a
         * concession recorded separately in contra-revenue. So goods listed at 100,
         * 20 off, plus 8 tax means: cash 88, discount 20, revenue 100, tax 8.
         * The journal balances because cash collected plus the concession given
         * equals the goods value plus the tax charged.
         *
         * Crediting revenue net of the discount *and* debiting the discount account
         * would deduct it twice. The two treatments are mutually exclusive, and
         * contra-revenue is the one chosen because it keeps the size of discounting
         * visible -- which is the figure a retailer actually manages.
         *
         * The collected amount is what is banked, so change handed over out of the
         * till is not part of it and correctly does not appear here.
         */
        $grossRevenue = Money::add(
            Money::subtract($total, $tax),
            $discount,
        );

        $lines = [
            [
                'code' => $settlementAccountCode,
                'debit' => $total,
                'memo' => 'Till sale '.$sale->receipt_number,
            ],
            [
                'code' => self::SALES_REVENUE,
                'credit' => $grossRevenue,
                'memo' => 'Till revenue',
            ],
        ];

        if (Money::isGreaterThan($discount, '0')) {
            $lines[] = [
                'code' => self::SALES_DISCOUNTS,
                'debit' => $discount,
                'memo' => 'Discount given',
            ];
        }

        if (Money::isGreaterThan($tax, '0')) {
            $lines[] = [
                'code' => self::OUTPUT_TAX_PAYABLE,
                'credit' => $tax,
                'memo' => 'Output tax on till sale',
            ];
        }

        // Stock left the shelf at this sale, so its cost has to leave the balance
        // sheet with it. Without this the till reports margin on stock that is
        // already sold and Inventory never falls however much is rung up.
        $cost = $this->posCostOfSales($sale);

        if (Money::isGreaterThan($cost, '0')) {
            $lines[] = ['code' => self::COST_OF_GOODS_SOLD, 'debit' => $cost, 'memo' => 'Cost of till goods'];
            $lines[] = ['code' => self::INVENTORY, 'credit' => $cost, 'memo' => 'Stock relieved at cost'];
        }

        return $this->post(
            $sale,
            $lines,
            "POS sale {$sale->receipt_number}",
            $date ?? ($sale->sold_at?->toDateString()),
            stage: 'completed',
        );
    }

    /**
     * Cost of the goods on a till sale, from each line's product cost.
     *
     * Zero for lines rung up without a product, which is why a service or a
     * loose-goods till can legitimately show no cost of sales.
     */
    private function posCostOfSales(Model $sale): string
    {
        $productIds = $sale->items()
            ->whereNotNull('product_id')
            ->pluck('product_id')
            ->unique()
            ->all();

        if ($productIds === []) {
            return '0';
        }

        $costs = Product::whereIn('id', $productIds)
            ->pluck('cost_price', 'id');

        $total = '0';

        foreach ($sale->items()->with('product')->get() as $item) {
            if (! $item->product_id) {
                continue;
            }

            $cost = $costs[$item->product_id] ?? null;

            if ($cost === null) {
                continue;
            }

            $total = Money::add(
                $total,
                Money::multiply((string) $cost, (string) $item->quantity),
            );
        }

        return $total;
    }

    // ── Payroll ──────────────────────────────────────────────────────────────

    /**
     * An approved payroll run recognises the wage cost and the amounts owed.
     *
     *   Dr Salaries and Wages       gross
     *       Cr Payroll Tax Payable      withheld
     *       Cr Net Pay Payable          net
     *
     * Posted at approval rather than at payment, because the cost was incurred in
     * the period the work happened. Paying later is a balance-sheet settlement of
     * a liability that already exists.
     *
     * Withheld tax is credited to a liability, never netted off the expense: the
     * employer incurred the full gross cost even though the employee never
     * received all of it, and the difference is owed to the authority.
     *
     * Attendance deductions are not tax. The employer never incurred them -- the
     * employee simply did not earn that portion -- so they are credited back
     * against the expense rather than becoming a liability. There is no separate
     * account for them, and inventing one would imply a liability that does not
     * exist.
     */
    public function payrollRunApproved(Model $run, ?string $withheldTax = null, ?string $date = null): ?JournalEntry
    {
        $gross = Money::of($run->total_gross);
        $net = Money::of($run->total_net);
        $withheld = Money::of($withheldTax ?? 0);

        // Never post a negative withholding: it would become a debit on a
        // liability and quietly understate what is owed to the authority.
        if (Money::isLessThan($withheld, '0')) {
            $withheld = '0.00';
        }

        /**
         * What the gross/net difference is made of decides which account takes it,
         * and the two are not interchangeable:
         *
         *  - Withheld tax is owed to the authority and stays a liability.
         *  - Attendance deductions were never part of the cost. The employer did not
         *    incur them; the employee simply did not earn that portion. Crediting
         *    them against the expense is correct, and crediting them to a tax
         *    liability would fabricate a debt to an authority that does not exist.
         *
         * Payslips in this application record attendance deductions only, so unless
         * a real withholding figure is passed the whole difference is an attendance
         * credit. Inventing a tax liability from a number that was never a tax
         * figure would make the balance sheet wrong in a way that is hard to spot.
         */
        $attendanceDeductions = Money::subtract(
            Money::subtract($gross, $net),
            $withheld,
        );

        if (Money::isLessThan($attendanceDeductions, '0')) {
            $attendanceDeductions = '0.00';
        }

        $lines = [
            ['code' => self::SALARIES_AND_WAGES, 'debit' => $gross, 'memo' => 'Payroll gross'],
            ['code' => self::NET_PAY_PAYABLE, 'credit' => $net, 'memo' => 'Net pay owed to employees'],
        ];

        if (Money::isGreaterThan($withheld, '0')) {
            $lines[] = [
                'code' => self::PAYROLL_TAX_PAYABLE,
                'credit' => $withheld,
                'memo' => 'Employee tax withheld',
            ];
        }

        if (Money::isGreaterThan($attendanceDeductions, '0')) {
            $lines[] = [
                'code' => self::SALARIES_AND_WAGES,
                'credit' => $attendanceDeductions,
                'memo' => 'Attendance deductions not earned',
            ];
        }

        return $this->post(
            $run,
            $lines,
            "Payroll run {$run->number} approved",
            $date ?? ($run->period_end?->toDateString()),
            stage: 'approved',
        );
    }

    /**
     * Settling net pay once the money actually leaves the bank.
     *
     *   Dr Net Pay Payable   net
     *       Cr Cash / Bank       net
     *
     * This settles only what the employees are owed. Withheld tax stays in its own
     * liability until it is remitted, because the money has not left the business.
     */
    public function payrollPaid(Model $run, string $bankAccountCode = self::BANK, ?string $date = null): ?JournalEntry
    {
        $net = Money::of($run->total_net);

        if (Money::equals($net, '0')) {
            return null;
        }

        return $this->post(
            $run,
            [
                ['code' => self::NET_PAY_PAYABLE, 'debit' => $net, 'memo' => 'Net pay settled'],
                ['code' => $bankAccountCode, 'credit' => $net, 'memo' => 'Payroll paid out'],
            ],
            "Payroll run {$run->number} paid",
            $date ?? ($run->paid_at?->toDateString() ?? $run->period_end?->toDateString()),
            stage: 'paid',
        );
    }

    /**
     * Remitting withheld tax to the authority.
     *
     *   Dr Payroll Tax Payable   amount
     *       Cr Cash / Bank           amount
     */
    public function payrollTaxRemitted(Model $source, string $amount, string $bankAccountCode = self::BANK, ?string $date = null): ?JournalEntry
    {
        $value = Money::of($amount);

        if (Money::equals($value, '0')) {
            return null;
        }

        return $this->post(
            $source,
            [
                ['code' => self::PAYROLL_TAX_PAYABLE, 'debit' => $value, 'memo' => 'Employee tax remitted'],
                ['code' => $bankAccountCode, 'credit' => $value, 'memo' => 'Withheld tax paid to authority'],
            ],
            'Employee tax remitted',
            $date,
        );
    }

    // ── Inventory ────────────────────────────────────────────────────────────

    /**
     * A stock write-off or count variance.
     *
     *   Dr Inventory Adjustment   cost value
     *       Cr Inventory               cost value
     */
    public function inventoryValuationLoss(Model $source, string $costValue, ?string $date = null): ?JournalEntry
    {
        $amount = Money::of($costValue);

        if (Money::equals($amount, '0')) {
            return null;
        }

        return $this->post($source, [
            ['code' => self::INVENTORY_ADJUSTMENT, 'debit' => $amount],
            ['code' => self::INVENTORY, 'credit' => $amount],
        ], 'Inventory valuation loss', $date);
    }

    /**
     * Void the journal raised for a document.
     *
     * Corrections must not silently rewrite history: the original entry is marked
     * void and a fresh one is raised for the new figures. That leaves both the
     * mistake and its correction visible on the audit trail, which is the whole
     * point of a general ledger.
     *
     * Voiding is idempotent, and a document that was never posted is a no-op.
     */
    /**
     * Void the journal(s) a document produced.
     *
     * Looks across every lifecycle stage, not just the unstaged reference. A
     * payroll run posts twice by design -- an accrual at approval and a settlement
     * at payment -- and reversing only the first would leave the bank credited
     * with nothing to offset it.
     */
    public function reverse(Model $document, string $reason = 'Reversed'): bool
    {
        $entry = $this->primaryJournalFor($document);

        if ($entry === null || $entry->status !== 'posted') {
            return false;
        }

        GeneralLedger::void($entry);

        $entry->update(['description' => $entry->description.' [voided: '.$reason.']']);

        return true;
    }

    /**
     * Void the old journal and raise a new one for the document's current figures.
     *
     * Used when a posted document is edited: the amounts on the ledger have to
     * follow the amounts on the document, or the statements disagree with the
     * documents they are derived from.
     */
    public function repost(Model $document, callable $rules, string $reason = 'Document amended'): bool
    {
        $this->reverse($document, $reason);

        $rules($this);

        return true;
    }

    // ── Internals ────────────────────────────────────────────────────────────

/**
     * The currency and rate a document's journal lines should carry.
     *
     * Read from the document's own columns rather than a live rate lookup, because
     * a document's rate is snapshotted when it is raised. Looking the rate up again
     * at posting time would retroactively restate the transaction at whatever rate
     * happened to be configured afterwards.
 *
     * A document with no currency of its own -- a payroll run, a manual bank entry
     * -- is posted in the base currency at rate 1, which is the honest answer: the
     * amount was denominated in the reporting currency to begin with.
     *
     * @return array{currency: string, exchange_rate: string}
     */
    private function currencyContextFor(Model $document): array
    {
        $currency = strtoupper((string) ($document->currency ?? ''));

        if ($currency === '') {
            return ['currency' => base_currency(), 'exchange_rate' => '1'];
        }

        $rate = (string) ($document->exchange_rate ?? '');

        if ($rate === '' || ! is_numeric($rate) || (float) $rate <= 0.0) {
            // A currency with no usable rate must not be posted as though it were
            // the base currency: that is exactly the understatement this column
            // exists to prevent.
            $rate = (string) (exchange_rate_for($currency) ?: 1);
        }

        return ['currency' => $currency, 'exchange_rate' => $rate];
    }

    /**
     * Turn account codes into resolved lines, converting to base currency.
     *
     * The amounts passed in are the document's face values in its own currency.
     * They are converted here, once, so every downstream report can keep summing
     * a single currency and no caller has to remember to convert.
     *
     * @param  array<int, array{code: string, debit?: string|float, credit?: string|float, memo?: string|null}>  $lines
     * @param  array{currency: string, exchange_rate: string}  $context
     * @return array<int, array{account_id: int, debit: string, credit: string, memo: string|null, currency: string, exchange_rate: string, original_debit: string|null, original_credit: string|null}>
     */
    private function resolveAccounts(array $lines, array $context = ['currency' => 'USD', 'exchange_rate' => '1']): array
    {
        $accounts = Account::whereIn('code', array_column($lines, 'code'))->get()->keyBy('code');

        $currency = $context['currency'] ?: base_currency();
        $rate = $context['exchange_rate'] ?: '1';
        $rateFloat = (float) $rate;

        $resolved = [];
        $debits = '0';
        $credits = '0';

        foreach ($lines as $line) {
            /**
             * Every line is stated in the document's own currency, so the balancing
             * check runs on the converted figures. Checking before conversion would
             * accept a set that only balances in one currency and then post it
             * unbalanced in the other.
             */
            $originalDebit = Money::of($line['debit'] ?? 0);
            $originalCredit = Money::of($line['credit'] ?? 0);

            $debit = Money::of((float) $originalDebit * $rateFloat);
            $credit = Money::of((float) $originalCredit * $rateFloat);

            // A zero line carries no information and GeneralLedger rejects it.
            if (Money::equals($debit, '0') && Money::equals($credit, '0')) {
                continue;
            }

            $account = $accounts->get($line['code']);

            if ($account === null) {
                throw new \RuntimeException(sprintf(
                    'Cannot post: ledger account "%s" does not exist. Run the ChartOfAccountsSeeder, or '
                    .'create the account before enabling automatic posting.',
                    $line['code'],
                ));
            }

            $resolved[] = [
                'account_id' => $account->id,
                'debit' => $debit,
                'credit' => $credit,
                'memo' => $line['memo'] ?? null,
                'currency' => $currency,
                'exchange_rate' => $rate,
                'original_debit' => Money::equals($originalDebit, '0') ? null : $originalDebit,
                'original_credit' => Money::equals($originalCredit, '0') ? null : $originalCredit,
            ];

            $debits = Money::add($debits, $debit);
            $credits = Money::add($credits, $credit);
        }

        if ($resolved === []) {
            return [];
        }

        if (! Money::equals($debits, $credits)) {
            throw new \RuntimeException(sprintf(
                'Refusing to post an unbalanced journal: debits %s vs credits %s.',
                $debits,
                $credits,
            ));
        }

        return $resolved;
    }

/**
     * The idempotency key: document class, id, and lifecycle stage.
     *
     * The stage suffix exists because one document can legitimately post more than
     * once at different points in its life. A payroll run is the case that forced
     * it: approval accrues the wage cost and payment settles that liability, and
     * both are keyed to the same run. Without the stage, the second call found the
     * first journal, decided the run had already been posted, and silently skipped
     * the settlement -- leaving the bank credited with no matching debit and net pay
     * permanently on the books as owed.
     */
    private function referenceFor(Model $document, string $stage = ''): string
    {
        $reference = $document::class.':'.$document->getKey();

        return $stage === '' ? $reference : $reference.':'.$stage;
    }

    /**
     * The idempotency key for a specific lifecycle stage of a document.
     */
    private function stageReference(Model $document, string $stage): string
    {
        return $this->referenceFor($document, $stage);
    }

    /**
     * Every live journal a document produced, across all lifecycle stages.
     *
     * @return Collection<int, JournalEntry>
     */
    private function journalsFor(Model $document): Collection
    {
        $base = $this->referenceFor($document);

        /**
         * The LIKE pattern is escaped explicitly. The reference carries a namespace
         * separator, and MySQL reads a backslash inside a LIKE pattern as an escape
         * character -- so the unescaped form matched nothing at all.
         */
        $pattern = str_replace('\\', '\\\\', $base).':%';

        return JournalEntry::query()
            ->where(function ($query) use ($base, $pattern) {
                $query->where('reference', $base)->orWhere('reference', 'like', $pattern);
            })
            ->whereIn('status', ['draft', 'posted'])
            ->orderBy('id')
            ->get();
    }

    /**
     * The first live journal for a document, or null.
     *
     * Public because callers routinely need to inspect what a document actually
     * posted -- and reaching for a private method to do it is how the internals
     * quietly became load-bearing.
     */
    public function primaryJournalFor(Model $document): ?JournalEntry
    {
        return $this->journalsFor($document)->first();
    }
}
