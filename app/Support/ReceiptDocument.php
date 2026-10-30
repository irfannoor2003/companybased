<?php

namespace App\Support;

/**
 * Presents a money-received or money-paid document as the shape the shared
 * receipt template renders.
 *
 * Three near-identical receipt templates existed -- sales payments, supplier
 * payments and withholding tax receipts -- and they drifted apart while claiming
 * to be the same letterhead. They now share one template, fed by this adapter, so
 * a receipt and the invoice it pays are produced from one design.
 *
 * The shared document template (documents/pdf.blade.php) is not usable here: it
 * assumes line items, a subtotal and a tax figure, and a receipt has none of those.
 * This is the reconciliation -- same visual language, different document shape.
 */
class ReceiptDocument
{
    public function __construct(
        public readonly mixed $receipt,
        public readonly string $docTitle,
        public readonly string $partyLabel,
        public readonly string $amountLabel,
        public readonly string $partyCodeLabel,
        public readonly string $dateLabel = 'Payment date',
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
     * Render the shared receipt template.
     */
    public function render(): string
    {
        /**
         * The receipt template of its own type now owns the colour and the
         * operator's additions.
         *
         * Before this the receipt hard-coded the company branding colour while
         * every other printed document followed the template the operator
         * managed on Settings -> Templates -- so restyling a receipt meant
         * changing company branding, which also changed every other screen in
         * the app. The colour order is now the same rule the frame applies:
         * template colour first, company branding as the fallback, built-in
         * last. The custom stylesheet and the custom footer HTML ride along the
         * same way; the LAYOUT stays the receipt's own, because a receipt is a
         * proof of payment, not an invoice without tax.
         */
        $layout = PdfLayout::forType('receipt');

        $brand = $layout->color('primary', settings('branding.primary_color', '#4f46e5'));

        return view('partials.pdf.receipt', [
            'receipt' => $this->receipt,
            'docTitle' => $this->docTitle,
            'partyLabel' => $this->partyLabel,
            'partyCodeLabel' => $this->partyCodeLabel,
            'amountLabel' => $this->amountLabel,
            'dateLabel' => $this->dateLabel,
            'againstLabel' => $this->againstLabel,
            'partyName' => $this->partyName(),
            'partyCode' => $this->partyCode(),
            'receiptDate' => $this->receiptDate(),
            'method' => $this->method(),
            'againstNumber' => $this->againstNumber(),
            'brandColor' => $brand,
            'brandTint' => $this->tint($brand),
            'customCss' => $layout->templateCss(),
            'footerHtml' => $layout->footerHtml(),
        ])->render();
    }

    /**
     * A very light wash of the brand colour, for the amount panel.
     *
     * DomPDF does not resolve CSS colour functions, so the tint is computed rather
     * than expressed as color-mix() or an rgba() literal with a variable.
     */
    private function tint(string $hex): string
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        if (strlen($hex) !== 6 || ! ctype_xdigit($hex)) {
            return '#eef2ff';
        }

        // Blend towards white by 92%.
        $rounds = [];
        foreach ([0, 2, 4] as $i) {
            $component = hexdec(substr($hex, $i, 2));
            $rounds[] = (int) round(255 - ((255 - $component) * 0.08));
        }

        return sprintf('#%02x%02x%02x', ...$rounds);
    }
}