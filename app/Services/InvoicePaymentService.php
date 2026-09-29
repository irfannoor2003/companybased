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

        if ($amount > $remaining) {
            throw new \RuntimeException(
                'Payment amount ('.money($amount, $invoice->currency).') exceeds the outstanding balance of '.money($remaining, $invoice->currency).'.'
            );
        }

        return $invoice;
    }

    /**
     * Remaining balance on an invoice: the total minus every payment already
     * recorded against it, ignoring $excludePaymentId when given.
     *
     * Always sums the existing payments — passing null excludes nothing, it
     * means "count them all".
     */
    public function remainingBalance(Model $invoice, ?int $excludePaymentId = null): float
    {
        $paid = (float) $invoice->payments()
            ->when($excludePaymentId !== null, fn ($q) => $q->whereKeyNot($excludePaymentId))
            ->sum('amount');

        return round((float) $invoice->total - $paid, 2);
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
     */
    public function recalculateStatus(Model $invoice): void
    {
        if ($invoice->status === 'cancelled') {
            return;
        }

        $newStatus = $invoice->isPaid()
            ? 'paid'
            : ((float) $invoice->paid_amount > 0 ? 'partially_paid' : $invoice->status);

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
     * @return class-string
     */
    private function statusEventClass(Model $invoice): string
    {
        return $invoice instanceof PurchaseInvoice ? PurchaseStatusEvent::class : SalesStatusEvent::class;
    }
}
