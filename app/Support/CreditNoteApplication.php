<?php

namespace App\Support;

use App\Models\SalesCreditNote;
use App\Models\SalesInvoice;
use App\Services\InvoicePaymentService;
use Illuminate\Support\Facades\DB;

/**
 * Settles an invoice from a credit note raised against it.
 *
 * `SalesCreditNote::applied_amount` sat in $fillable and was read by the customer
 * balance, the statements and the ledger, but nothing ever wrote it — so a credit
 * note had no financial effect at all. Issuing one against a 2,000.00 invoice
 * left the balance at 2,000.00 and printed a 0.00 credit line.
 *
 * `applied_amount` means the value credited to the customer account, which is
 * what `SalesCustomer::balance()` subtracts — so it is the note's full value. The
 * amount an *invoice* can absorb is capped separately, at what that invoice still
 * owes, and recorded on the invoice as `credit_note_applied`.
 *
 * The two are deliberately different numbers. Capping `applied_amount` at the
 * invoice's balance instead would lose the excess entirely: a 900.00 note against
 * a 300.00 invoice would credit only 300, and the customer would never see the
 * other 600 they are owed. Capping on the invoice keeps both halves of the
 * document honest at once.
 *
 * Recomputed from the note rows rather than incremented, so editing or deleting
 * a note cannot leave the invoice and the customer ledger disagreeing.
 */
final class CreditNoteApplication
{
    /**
     * Credit the note to the customer and settle as much of its invoice as that
     * invoice still owes.
     *
     * Runs in a transaction with the invoice locked, since two notes saved at the
     * same moment would otherwise both read the same outstanding balance and each
     * apply against it.
     *
     * @return float The value credited to the customer.
     */
    public static function sync(SalesCreditNote $note): float
    {
        $credited = round((float) $note->total, 2);

        $note->forceFill(['applied_amount' => $credited])->save();

        if (! $note->invoice_id) {
            // An invoice-less note credits the customer account directly. That is
            // legitimate — it is how a goodwill credit is recorded — so there is
            // no invoice to settle.
            return $credited;
        }

        DB::transaction(function () use ($note, $credited): void {
            $invoice = SalesInvoice::query()->lockForUpdate()->findOrFail($note->invoice_id);

            // Everything the *other* notes already took, so concurrent notes
            // cannot both claim the same part of the balance.
            $otherNotes = (float) $invoice->creditNotes()
                ->whereKeyNot($note->id)
                ->sum('credit_note_applied');

            $outstanding = max(
                0.0,
                round((float) $invoice->total - (float) $invoice->paid_amount - (float) $invoice->credit_applied, 2),
            );

            $absorbed = round(max(0.0, min($credited, $outstanding - $otherNotes)), 2);

            $note->forceFill(['credit_note_applied' => $absorbed])->save();

            $invoice->update([
                'credit_note_applied' => round($otherNotes + $absorbed, 2),
            ]);

            app(InvoicePaymentService::class)->recalculateStatus($invoice->refresh());
        });

        return $credited;
    }

    /**
     * Re-derive an invoice's credit-note total after one of its notes changed.
     */
    public static function resyncInvoice(SalesInvoice $invoice): void
    {
        $invoice->update([
            'credit_note_applied' => round((float) $invoice->creditNotes()->sum('credit_note_applied'), 2),
        ]);

        app(InvoicePaymentService::class)->recalculateStatus($invoice->refresh());
    }
}