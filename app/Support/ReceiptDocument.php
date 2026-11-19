<?php

namespace App\Support;

/**
 * Compatibility adapter for the three money-receipt documents.
 *
 * Receipts used to print from a standalone template that ignored the operator's
 * document template. They now print from the SAME renderer as every other
 * document -- `DocumentData` + `documents/pdf.blade.php` -- so a receipt carries
 * the same letterhead, style, blocks, colours and custom header/footer as the
 * invoice it settles. The amount panel is the `receipt` block, and the invoice's
 * item and total blocks are switched off for receipts.
 *
 * The factory wording is kept because it is still the clearest statement of what
 * distinguishes the three documents: who the money came from or went to, what it
 * is called, and which document it settles. The renderer no longer needs it --
 * the wording now lives in `DocumentData::TYPES` -- but callers may still build a
 * `ReceiptDocument` directly, so the API stays.
 */
class ReceiptDocument
{
    public function __construct(
        public readonly mixed $receipt,
        public readonly string $docTitle,
        public readonly string $partyLabel,
        public readonly string $amountLabel,
        public readonly string $partyCodeLabel,
        public readonly ?string $dateLabel = 'Payment date',
        public readonly ?string $againstLabel = 'Against invoice',
    ) {}

    public static function salesPayment(mixed $payment): self
    {
        return new self(
            receipt: $payment,
            docTitle: 'Payment Receipt',
            partyLabel: 'Received from',
            amountLabel: 'Amount received',
            partyCodeLabel: 'Customer code',
            dateLabel: 'Payment date',
            againstLabel: 'Against invoice',
        );
    }

    public static function supplierPayment(mixed $payment): self
    {
        return new self(
            receipt: $payment,
            docTitle: 'Payment Receipt',
            partyLabel: 'Paid to',
            amountLabel: 'Amount paid',
            partyCodeLabel: 'Supplier code',
            dateLabel: 'Payment date',
            againstLabel: 'Against bill',
        );
    }

    public static function withholdingTaxReceipt(mixed $receipt): self
    {
        return new self(
            receipt: $receipt,
            docTitle: 'Withholding Tax Receipt',
            partyLabel: 'Received from',
            amountLabel: 'Tax deducted',
            partyCodeLabel: 'Customer code',
            dateLabel: 'Receipt date',
            againstLabel: 'Against invoice',
        );
    }

    public function partyName(): ?string
    {
        return $this->receipt->customer?->company_name
            ?? $this->receipt->supplier?->company_name
            ?? $this->receipt->customer_name
            ?? $this->receipt->supplier_name;
    }

    public function partyCode(): ?string
    {
        return $this->receipt->customer?->short_code ?? $this->receipt->supplier?->short_code;
    }

    public function receiptDate()
    {
        return $this->receipt->payment_date
            ?? $this->receipt->receipt_date
            ?? $this->receipt->withholding_date;
    }

    public function method(): ?string
    {
        return $this->receipt->method ?? null;
    }

    public function againstNumber(): ?string
    {
        return $this->receipt->invoice?->number ?? null;
    }

    /**
     * Render the document through the shared template. The model's own type
     * decides its wording, so a `ReceiptDocument` built through any factory
     * prints exactly what its controller now prints.
     */
    public function render(): string
    {
        return view('documents.pdf', DocumentData::build($this->receipt))->render();
    }
}
