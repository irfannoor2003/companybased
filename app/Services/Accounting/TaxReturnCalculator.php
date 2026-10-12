<?php

namespace App\Services\Accounting;

use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Derive a tax return's figures from the ledger.
 *
 * Every figure on a tax return used to be typed in by hand. That is the weakest
 * possible position for the most consequential document the business produces: the
 * return cannot be reproduced from the books that generated it, two people typing
 * the same month will get different answers, and a mistyped digit is undetectable
 * until it is rejected by the authority.
 *
 * The figures are read out of the general ledger, restricted to POSTED journals in
 * the period. Posted-only matters more here than anywhere else: a return that
 * included a draft would move when someone remembered to finish approving an
 * invoice, which is precisely the kind of quiet revision that makes a filed return
 * irreproducible.
 *
 * The mapping, and why:
 *
 *   Output tax  Cr 2200  Output Tax Payable        tax charged to customers
 *   Input tax   Dr 1310  Input Tax Receivable       tax reclaimed on purchases
 *
 * Output tax is read as the MOVEMENT on the account over the period, not its
 * closing balance. The closing balance is the cumulative liability ever owed; the
 * movement is what this period's sales charged. Crediting prior-period invoices
 * inside this period shows up as a negative movement, which is correct: a credit
 * note this month reduces this month's output tax.
 */
class TaxReturnCalculator
{
    /**
     * Compute the figures for a period.
     *
     * @return array{
     *     gross_receipts: string,
     *     taxable_amount: string,
     *     tax_collected: string,
     *     tax_credits: string,
     *     tax_due: string,
     *     output_tax_reported: string,
     *     input_tax_claimed: string,
     *     has_activity: bool
     * }
     */
    public function compute(string $periodStart, string $periodEnd): array
    {
        $output = $this->movementOn(LedgerPosting::OUTPUT_TAX_PAYABLE, $periodStart, $periodEnd);
        $input = $this->movementOn(LedgerPosting::INPUT_TAX_RECEIVABLE, $periodStart, $periodEnd);
        $revenue = $this->movementOnType('revenue', $periodStart, $periodEnd);

        // Output tax payable is a credit-normal liability: tax charged to customers
        // credits it, so credits minus debits is the tax this period charged. A
        // credit note debits it and correctly reduces the figure.
        $taxCollected = Money::subtract($output['credit'], $output['debit']);

        // Input tax receivable is debit-normal: tax reclaimed debits it, so debits
        // minus credits is the tax this period reclaimed.
        $taxCredits = Money::subtract($input['debit'], $input['credit']);

        /**
         * Taxable sales, from the revenue accounts, which are credited NET of tax.
         * That makes this the value excluding tax -- which is the basis the
         * authority assesses on -- with no rate assumption anywhere.
         *
         * Derived from the ledger rather than from the sales documents. Reading
         * `sales_invoices.total` here looked reasonable and was wrong in a way that
         * only showed up on a return: the documents and the ledger disagreed the
         * moment a document was saved without posting, an invoice was amended, or a
         * credit note was issued -- and the return then reported a gross of 0
         * alongside 18,000 of tax collected, with a taxable amount of minus
         * 18,000. One source of truth, so the two figures cannot contradict it.
         *
         * Credit notes debit the contra-revenue accounts, so a note issued this
         * month reduces this month's taxable value rather than being ignored.
         */
        $taxableAmount = Money::subtract($revenue['credit'], $revenue['debit']);

        // What was invoiced, tax included: the value charged plus the tax on it.
        $grossReceipts = Money::add($taxableAmount, $taxCollected);

        return [
            'gross_receipts' => $grossReceipts,
            'taxable_amount' => $taxableAmount,
            'tax_collected' => $taxCollected,
            'tax_credits' => $taxCredits,
            'tax_due' => Money::subtract($taxCollected, $taxCredits),
            'output_tax_reported' => $taxCollected,
            'input_tax_claimed' => $taxCredits,
            'has_activity' => $this->hasActivity($periodStart, $periodEnd),
        ];
    }

    /**
     * Debit and credit totals on one account over a period, posted entries only.
     *
     * @return array{debit: string, credit: string}
     */
    private function movementOn(string $accountCode, string $from, string $to): array
    {
        $row = DB::table('journal_entry_items')
            ->join('accounts', 'accounts.id', '=', 'journal_entry_items.account_id')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_items.journal_entry_id')
            ->where('accounts.code', $accountCode)
            ->where('journal_entries.status', 'posted')
            ->whereDate('journal_entries.entry_date', '>=', $from)
            ->whereDate('journal_entries.entry_date', '<=', $to)
            ->selectRaw('COALESCE(SUM(journal_entry_items.debit), 0) AS debit')
            ->selectRaw('COALESCE(SUM(journal_entry_items.credit), 0) AS credit')
            ->first();

        return [
            'debit' => number_format((float) ($row->debit ?? 0), 2, '.', ''),
            'credit' => number_format((float) ($row->credit ?? 0), 2, '.', ''),
        ];
    }

    /**
     * Debit and credit totals across every account of a type, posted entries only.
     *
     * Used for revenue, where the accounts differ per deployment: a business with a
     * contra-revenue account for sales returns still gets the right total, and one
     * without it is unaffected.
     *
     * @return array{debit: string, credit: string}
     */
    private function movementOnType(string $type, string $from, string $to): array
    {
        $row = DB::table('journal_entry_items')
            ->join('accounts', 'accounts.id', '=', 'journal_entry_items.account_id')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_items.journal_entry_id')
            ->where('accounts.type', $type)
            ->where('journal_entries.status', 'posted')
            ->whereDate('journal_entries.entry_date', '>=', $from)
            ->whereDate('journal_entries.entry_date', '<=', $to)
            ->selectRaw('COALESCE(SUM(journal_entry_items.debit), 0) AS debit')
            ->selectRaw('COALESCE(SUM(journal_entry_items.credit), 0) AS credit')
            ->first();

        return [
            'debit' => number_format((float) ($row->debit ?? 0), 2, '.', ''),
            'credit' => number_format((float) ($row->credit ?? 0), 2, '.', ''),
        ];
    }

    /**
     * Whether anything at all was posted in the period.
     *
     * Distinguishing "no tax to report" from "nothing happened" matters: the first
     * is a nil return the business files, the second usually means the period was
     * never worked on and the figure would be wrong to file as zero.
     */
    private function hasActivity(string $from, string $to): bool
    {
        return DB::table('journal_entries')
            ->where('status', 'posted')
            ->whereDate('entry_date', '>=', $from)
            ->whereDate('entry_date', '<=', $to)
            ->exists();
    }
}
