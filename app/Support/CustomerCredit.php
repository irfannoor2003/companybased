<?php

namespace App\Support;

use App\Models\SalesCustomer;
use App\Models\SalesInvoice;
use App\Models\SalesStatusEvent;
use App\Services\InvoicePaymentService;
use Illuminate\Support\Facades\DB;

/**
 * Customer credit: money the customer has already handed over that has not been
 * matched to a bill yet.
 *
 * The ledger sign convention is unchanged and everything else already follows
 * from it — `SalesCustomer::balance()` is positive when the customer owes us and
 * negative when we owe them, so credit held is simply the negative of the
 * balance. That is why no new "credit" column is needed on the customer: the
 * system already had a representation for "we owe this customer", it was just
 * impossible to reach it by overpaying.
 *
 * Credit arrives two ways, both feeding the same figure:
 *
 *  - an overpayment, where the amount above the invoice balance is recorded as a
 *    receipt with no invoice against it, and
 *  - a negative opening balance, for a customer who was already in credit with
 *    us before their first document here.
 *
 * It is consumed by the next invoice via `applyTo()`. Consumption is automatic
 * but bounded by what the customer actually holds and never exceeds the invoice
 * total, and it is recorded on the invoice so the document states on its face
 * that part of it was settled from the balance rather than paid in cash.
 */
final class CustomerCredit
{
    /**
     * The statuses whose totals make up a customer balance. Mirrors
     * `SalesCustomer::balance()`; a draft or cancelled invoice is not a demand
     * for money, so it contributes nothing and has nothing to settle.
     */
    public const COUNTED_STATUSES = ['sent', 'partially_paid', 'overdue', 'paid'];

    /**
     * Money this customer holds with us, never negative.
     *
     * Excludes the given invoice's own total, so an invoice can ask how much
     * credit it could draw without the answer already including the bill it is
     * trying to settle.
     */
    public static function availableFor(SalesCustomer $customer, ?SalesInvoice $except = null): float
    {
        return round(max(0.0, -self::balanceExcluding($customer, $except)), 2);
    }

    /**
     * The customer's balance ignoring one invoice.
     *
     * Needed while building that very invoice: once its total is on the ledger,
     * reading the plain balance would measure the credit against the new bill and
     * under-report what is genuinely available.
     */
    public static function balanceExcluding(SalesCustomer $customer, ?SalesInvoice $except): float
    {
        if (! $except) {
            return $customer->balance();
        }

        // Only what this invoice actually contributes to the balance is removed.
        // A draft is excluded from the balance already, so subtracting it would
        // invent credit that does not exist.
        $contribution = in_array($except->status, self::COUNTED_STATUSES, true)
            ? (float) $except->total
            : 0.0;

        return round($customer->balance() - $contribution, 2);
    }

    /**
     * Settle an invoice from the customer's credit.
     *
     * Idempotent: the amount is recomputed from the credit the customer holds
     * *excluding this invoice*, so re-running after the invoice shrank gives back
     * the difference rather than stacking a second draw on top of the first.
     *
     * Runs in a transaction and locks the customer row, because two invoices
     * saved at the same moment would otherwise each read the same credit balance
     * and each spend all of it.
     *
     * @param  float|null  $cap  Draw at most this much. Defaults to the whole
     *                            of the available credit, which is itself capped
     *                            at the invoice total. Pass 0.0 to release.
     * @return float The change in what this invoice has settled: positive when
     *               credit was drawn, negative when it was given back.
     */
    public static function applyTo(SalesInvoice $invoice, ?float $cap = null): float
    {
        // A draft is not a demand for money yet, and a cancelled one never will
        // be, so credit is left where it is until the document is actually sent.
        if (! in_array($invoice->status, self::COUNTED_STATUSES, true)) {
            return 0.0;
        }

        return DB::transaction(function () use ($invoice, $cap): float {
            $customer = SalesCustomer::query()->lockForUpdate()->findOrFail($invoice->customer_id);

            $available = self::availableFor($customer, $invoice);

            if ($cap !== null) {
                $available = min($available, max(0.0, round($cap, 2)));
            }

            $target = round(min($available, (float) $invoice->total), 2);

            $previous = round((float) $invoice->credit_applied, 2);
            $change = round($target - $previous, 2);

            if ($change === 0.0) {
                return 0.0;
            }

            $invoice->update(['credit_applied' => $target]);
            $invoice->refresh();

            SalesStatusEvent::create([
                'trackable_type' => SalesInvoice::class,
                'trackable_id' => $invoice->id,
                'from_status' => $invoice->status,
                'to_status' => $invoice->status,
                'user_id' => auth()->id(),
                'note' => $change > 0
                    ? money($target, $invoice->currency).' settled from the customer balance'
                    : money(-$change, $invoice->currency).' returned to the customer balance',
            ]);

            // Credit is a settlement, not a cash receipt, so it must never be
            // added to paid_amount: that column is recomputed from the payment
            // rows and would stop matching them.
            app(InvoicePaymentService::class)->recalculateStatus($invoice);

            return $change;
        });
    }

    /**
     * Give credit back to the pool — used when an invoice shrinks below what the
     * credit already settled.
     */
    public static function releaseFrom(SalesInvoice $invoice): float
    {
        return self::applyTo($invoice, 0.0);
    }
}