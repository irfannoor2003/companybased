<?php

namespace App\Services;

use App\Models\PurchaseInvoice;
use App\Models\PurchaseStatusEvent;
use App\Models\SalesInvoice;
use App\Models\SalesStatusEvent;
use Illuminate\Database\Eloquent\Model;

/**
 * The shared rules behind "record a payment against an invoice".
 *
 * Sales and supplier payments were two near-verbatim 300-line controllers:
 * the same row lock, the same ownership check, the same currency check, the
 * same overpayment guard, the same remaining-balance maths and the same status
 * machine, differing only in whether the document was a SalesInvoice or a
 * PurchaseInvoice. Those rules live here so they cannot drift apart.
 */
class InvoicePaymentService
{
    /**
     * Lock an invoice for update and assert the payment is legal against it.
     *
     * @template T of SalesInvoice|PurchaseInvoice
     *
     * @param  T  $invoice
     * @return T
     *
     * @throws \RuntimeException when ownership, currency or amount is invalid
     */
    public function lockAndGuard(
        Model $invoice,
        int $counterpartyId,
        ?string $currency,
        float $amount,
        ?int $excludePaymentId = null,
        bool $allowOverpayment = false,
    ): Model {
        /** @var SalesInvoice|PurchaseInvoice $invoice */
        $invoice = $invoice->newQuery()->lockForUpdate()->findOrFail($invoice->id);

        $ownerId = $this->ownerId($invoice);

        if ($counterpartyId !== $ownerId) {
            throw new \RuntimeException('The selected counterparty does not own this invoice.');
        }

        if ($currency !== null && strtoupper($currency) !== strtoupper((string) $invoice->currency)) {
            throw new \RuntimeException('Payment currency must match the invoice currency.');
        }

        // The remaining balance is the total minus everything already recorded.
        // On an edit the payment being edited is excluded, or it would count
        // against itself. On a new payment every existing payment counts, so a
        // second payment cannot push the invoice past its total.
        $remaining = $this->remainingBalance($invoice, $excludePaymentId);

        if (! $allowOverpayment && $amount > $remaining) {
            throw new \RuntimeException(
                'Payment amount ('.money($amount, $invoice->currency).') exceeds the outstanding balance of '.money($remaining, $invoice->currency).'.'
            );
        }

        return $invoice;
    }

    /**
     * Divide a received amount into the part that settles the invoice and the
     * part that has nowhere to go but the customer's balance.
     *
     * Recording the whole amount against the invoice is not an option: it would
     * leave paid_amount above the total, which is a negative balance that reads
     * as paid, breaks the "new total is below the amount already paid" guard on
     * the next edit, and makes the invoice's paid figure disagree with its
     * receipt rows. So the surplus becomes a second receipt with no invoice
     * against it — already how an advance is represented, and already something
     * `SalesCustomer::balance()` subtracts.
     *
     * @return array{applied: float, surplus: float}
     */
    public function splitFor(Model $invoice, float $amount, ?int $excludePaymentId = null): array
    {
        $remaining = max(0.0, $this->remainingBalance($invoice, $excludePaymentId));

        $applied = min($amount, $remaining);

        return [
            'applied' => round($applied, 2),
            'surplus' => round(max(0.0, $amount - $applied), 2),
        ];
    }

    /**
     * Remaining balance on an invoice: the total, less every payment already
     * recorded against it, less customer credit already spent, less anything a
     * credit note has already returned.
     *
     * Always sums the existing payments — passing null excludes nothing, it
     * means "count them all".
     *
     * The credit columns are read defensively rather than assumed, because this
     * service is shared with the supplier side where an invoice can be overpaid
     * by a debit note instead.
     */
    public function remainingBalance(Model $invoice, ?int $excludePaymentId = null): float
    {
        $paid = (float) $invoice->payments()
            ->when($excludePaymentId !== null, fn ($q) => $q->whereKeyNot($excludePaymentId))
            ->sum('amount');

        $credit = (float) ($invoice->getAttribute('credit_applied') ?? 0)
            + (float) ($invoice->getAttribute('credit_note_applied') ?? 0);

        return round((float) $invoice->total - $paid - $credit, 2);
    }

    /**
     * Add a payment to an invoice's paid_amount.
     */
    public function applyPaidAmount(Model $invoice, float $amount): void
    {
        $invoice->update([
            'paid_amount' => round((float) $invoice->paid_amount + $amount, 2),
        ]);
    }

    /**
     * Drive the invoice's payment status and record the transition.
     *
     * Cancelled invoices are left alone, and a no-op transition writes nothing
     * so the status-event history stays meaningful.
     *
     * The unpaid branch has to be able to move *backwards*. It used to keep the
     * current status whenever nothing had been paid, which meant an invoice that
     * had been `partially_paid` and then had its payment deleted, or moved to
     * another invoice, stayed `partially_paid` while recording a paid_amount of
     * zero — an invoice that claims money was received when none was. Only the
     * statuses that describe an invoice nobody has paid yet are preserved;
     * anything else drops back to `sent`.
     */
    public function recalculateStatus(Model $invoice): void
    {
        if ($invoice->status === 'cancelled') {
            return;
        }

        $newStatus = match (true) {
            $invoice->isPaid() => 'paid',
            // Credit settles an invoice exactly as cash does, so an invoice held
            // open by credit alone is partially paid, not untouched. Reading
            // paid_amount here left a credit-settled invoice showing as fully
            // unpaid, which is what a customer complains about on their bill.
            $this->settledAmount($invoice) > 0 => 'partially_paid',
            in_array($invoice->status, ['draft', 'sent', 'overdue'], true) => $invoice->status,
            default => 'sent',
        };

        if ($newStatus === $invoice->status) {
            return;
        }

        $this->statusEventClass($invoice)::create([
            'trackable_type' => $invoice::class,
            'trackable_id' => $invoice->id,
            'from_status' => $invoice->status,
            'to_status' => $newStatus,
            'user_id' => auth()->id(),
            'note' => 'Automatic status update',
        ]);

        $invoice->update(['status' => $newStatus]);
    }

    /**
     * The counterparty (customer or supplier) an invoice belongs to.
     */
    public function ownerId(Model $invoice): int
    {
        return (int) ($invoice->customer_id ?? $invoice->supplier_id);
    }

    /**
     * Cash received plus credit already spent on the invoice, from the customer's
     * forward balance and from credit notes.
     *
     * Read defensively because the supplier side has neither column: there an
     * invoice is settled by cash or by a debit note applied elsewhere, never by
     * carried-forward credit.
     */
    private function settledAmount(Model $invoice): float
    {
        return round(
            (float) $invoice->paid_amount
            + (float) ($invoice->getAttribute('credit_applied') ?? 0)
            + (float) ($invoice->getAttribute('credit_note_applied') ?? 0),
            2,
        );
    }

    /**
     * @return class-string
     */
    private function statusEventClass(Model $invoice): string
    {
        return $invoice instanceof PurchaseInvoice ? PurchaseStatusEvent::class : SalesStatusEvent::class;
    }
}
