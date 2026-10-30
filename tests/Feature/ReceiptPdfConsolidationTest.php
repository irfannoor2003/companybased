<?php

namespace Tests\Feature;

use App\Models\DocumentTemplate;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\SalesPayment;
use App\Models\SupplierPayment;
use App\Models\WithholdingTaxReceipt;
use App\Support\ReceiptDocument;
use Barryvdh\DomPDF\Facade\Pdf;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * Three near-identical receipt templates existed -- sales payments, supplier
 * payments and withholding tax receipts -- and they drifted while claiming to be
 * the same letterhead. Printing a receipt next to the invoice it pays produced two
 * visibly different documents from one company.
 *
 * They now share one template fed by an adapter. These tests prove DomPDF still
 * accepts the consolidated template, that each document type still carries its own
 * figures and its own wording, and that no field was lost in the merge.
 */
class ReceiptPdfConsolidationTest extends TestCase
{
    use SeedsDatabase;

    private function salesPayment(float $amount = 1234.50): SalesPayment
    {
        $customer = $this->customer('Receipt Co', 'ap@receipt.test');
        $customer->update(['short_code' => 'RCPT-1']);

        return SalesPayment::create([
            'number' => 'RCPT-0001',
            'invoice_id' => $this->invoice('INV-RECEIPT-1')->id,
            'customer_id' => $customer->id,
            'amount' => (string) $amount,
            'payment_date' => now()->subDay()->toDateString(),
            'method' => 'bank_transfer',
            'reference' => 'BANK-REF-9',
            'currency' => 'PKR',
            'exchange_rate' => 1,
            'notes' => 'Paid in full by transfer',
        ]);
    }

    private function invoice(string $number): SalesInvoice
    {
        return SalesInvoice::create([
            'number' => $number,
            'customer_id' => $this->customer('Receipt Co', 'ap@receipt.test')->id,
            'issue_date' => now()->subDays(10)->toDateString(),
            'due_date' => now()->addDays(20)->toDateString(),
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
    }

    private function supplierPayment(): SupplierPayment
    {
        $supplier = $this->supplier('Vendor Plc');
        $supplier->update(['short_code' => 'SUP-9']);

        $bill = PurchaseInvoice::create([
            'number' => 'PIN-1',
            'supplier_id' => $supplier->id,
            'issue_date' => now()->subDay()->toDateString(),
            'status' => 'sent',
            'currency' => 'PKR',
            'exchange_rate' => 1,
            'subtotal' => '500.00',
            'tax_amount' => '0.00',
            'total' => '500.00',
            'paid_amount' => '0',
        ]);

        return SupplierPayment::create([
            'number' => 'SP-0001',
            'invoice_id' => $bill->id,
            'supplier_id' => $supplier->id,
            'amount' => '500.00',
            'payment_date' => now()->subDay()->toDateString(),
            'method' => 'cash',
            'currency' => 'PKR',
            'exchange_rate' => 1,
        ]);
    }

    private function withholdingReceipt(): WithholdingTaxReceipt
    {
        return WithholdingTaxReceipt::create([
            'number' => 'WHT-0001',
            'customer_id' => $this->customer('Receipt Co', 'ap@receipt.test')->id,
            'invoice_id' => $this->invoice('INV-WHT-1')->id,
            'receipt_date' => now()->subDay()->toDateString(),
            'amount' => '150.00',
            'tax_rate_percent' => '15.00',
            'tax_amount' => '150.00',
            'currency' => 'PKR',
        ]);
    }

    public function test_dom_pdf_still_accepts_the_consolidated_receipt_template(): void
    {
        $pdf = Pdf::loadHTML(ReceiptDocument::salesPayment($this->salesPayment())->render())
            ->setPaper('a4', 'portrait')
            ->output();

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertGreaterThan(1000, strlen($pdf), 'an empty PDF means DomPDF silently rejected the template');
    }

    public function test_every_receipt_type_still_produces_pdf_bytes(): void
    {
        $cases = [
            ReceiptDocument::salesPayment($this->salesPayment()),
            ReceiptDocument::supplierPayment($this->supplierPayment()),
            ReceiptDocument::withholdingTaxReceipt($this->withholdingReceipt()),
        ];

        foreach ($cases as $case) {
            $pdf = Pdf::loadHTML($case->render())->setPaper('a4', 'portrait')->output();
            $this->assertStringStartsWith('%PDF', $pdf);
        }
    }

    public function test_a_customer_receipt_reads_as_money_in(): void
    {
        $html = ReceiptDocument::salesPayment($this->salesPayment(1234.50))->render();

        $this->assertStringContainsString('Payment Receipt', $html);
        $this->assertStringContainsString('Received from', $html, 'a customer receipt is money in');
        $this->assertStringContainsString('Amount received', $html);
        $this->assertStringContainsString('RCPT-0001', $html);
        $this->assertStringContainsString('Receipt Co', $html);
        $this->assertStringContainsString('RCPT-1', $html, 'the customer short code is shown');
        $this->assertStringContainsString('BANK-REF-9', $html, 'the bank reference is not lost');
        $this->assertStringContainsString('Bank transfer', $html, 'the method is humanised, matching the app-wide convention');
        $this->assertStringContainsString('Customer code', $html, 'the code label names the party, not the verb');
        $this->assertStringContainsString('INV-RECEIPT-1', $html, 'the invoice it settles');
        $this->assertStringContainsString('1,234.50', $html, 'the amount');
        $this->assertStringContainsString('Paid in full by transfer', $html, 'the notes');
    }

    public function test_a_supplier_payment_reads_as_money_out(): void
    {
        $html = ReceiptDocument::supplierPayment($this->supplierPayment())->render();

        $this->assertStringContainsString('Paid to', $html, 'a supplier payment is money out');
        $this->assertStringContainsString('Amount paid', $html);
        $this->assertStringContainsString('Against bill', $html, 'not "invoice" -- it settles a bill');
        $this->assertStringContainsString('Vendor Plc', $html);
        $this->assertStringContainsString('SUP-9', $html);
        $this->assertStringContainsString('PIN-1', $html);
    }

    public function test_a_withholding_tax_receipt_keeps_its_own_wording(): void
    {
        $html = ReceiptDocument::withholdingTaxReceipt($this->withholdingReceipt())->render();

        $this->assertStringContainsString('Withholding Tax Receipt', $html);
        $this->assertStringContainsString('Tax deducted', $html, 'it is tax withheld, not a sale');
        $this->assertStringContainsString('WHT-0001', $html);
        $this->assertStringContainsString('150.00', $html);
        $this->assertStringNotContainsString('Amount received', $html, 'tax withheld is not a receipt of the gross amount');
    }

    /**
     * All three come out of one template. That is the entire point of the change:
     * the three designs drifted precisely because they were separate copies.
     */
    public function test_all_receipts_share_one_template(): void
    {
        $this->assertFileExists(resource_path('views/partials/pdf/receipt.blade.php'));

        foreach ([
            'sales/payments/pdf.blade.php',
            'suppliers/supplier_payments/pdf.blade.php',
            'sales/withholding_tax_receipts/pdf.blade.php',
        ] as $obsolete) {
            $this->assertFileDoesNotExist(
                resource_path('views/'.$obsolete),
                'a duplicated receipt template is back; the three designs will drift again',
            );
        }
    }

    /**
     * DomPDF does not resolve CSS custom properties, so the brand colour is
     * interpolated. A malformed setting must not emit an unparseable colour and
     * render a blank page.
     */
    public function test_a_malformed_brand_colour_still_renders(): void
    {
        // One document, re-rendered under each colour: creating a fresh payment per
        // iteration would collide on the unique customer short_code.
        $document = ReceiptDocument::salesPayment($this->salesPayment());

        foreach (['#4f46e5', '#fff', '4f46e5', 'not-a-colour', ''] as $value) {
            \App\Models\Setting::updateOrCreate(
                ['key' => 'branding.primary_color'],
                ['value' => $value, 'group' => 'branding', 'is_public' => true],
            );
            \App\Models\Setting::flushCache();

            $pdf = Pdf::loadHTML($document->render())
                ->setPaper('a4', 'portrait')
                ->output();

            $this->assertStringStartsWith('%PDF', $pdf, 'brand colour "'.$value.'" broke rendering');
        }
    }

    /**
     * The receipt follows the receipt TEMPLATE, the way every other printed
     * document follows its template.
     *
     * Before this the receipt hard-coded the company branding colour while
     * invoices answered the template the operator managed on Settings ->
     * Templates -- which made restyling a receipt a company-wide change and
     * left the template row for type `receipt` (which the seeder has always
     * written) unread. The colour, the operator's stylesheet and the
     * operator's footer must reach the receipt; the LAYOUT must stay the
     * receipt's own, because a proof of payment is not an invoice shape.
     */
    public function test_a_receipt_follows_the_receipt_templates_colour_css_and_footer(): void
    {
        $previous = DocumentTemplate::query()
            ->where('type', 'receipt')
            ->where('is_default', true)
            ->first();

        DocumentTemplate::query()
            ->where('type', 'receipt')
            ->update(['is_default' => false]);

        DocumentTemplate::create([
            'name' => 'Operator receipt '.uniqid(),
            'type' => 'receipt',
            'style' => 'rail',
            'colors' => ['primary' => '#7a0011', 'accent' => '#059669', 'text' => '#1f2937'],
            'header_html' => null,
            'footer_html' => '<p>RECEIPT FOOTER MARKER</p>',
            'css' => '.receipt-op-css { color: #00d; }',
            'is_default' => true,
        ]);

        try {
            $html = ReceiptDocument::salesPayment($this->salesPayment())->render();

            // Company branding is #4f46e5 in this suite; only the template can
            // put #7a0011 on the page.
            $this->assertStringContainsString(
                '#7a0011',
                $html,
                'the receipt template colour wins over company branding',
            );

            $this->assertStringContainsString(
                'RECEIPT FOOTER MARKER',
                $html,
                'the operator footer prints after the built-in one',
            );

            $this->assertStringContainsString(
                '.receipt-op-css',
                $html,
                'the operator stylesheet is signed into the page',
            );
        } finally {
            DocumentTemplate::query()->where('name', 'like', 'Operator receipt %')->delete();

            $previous?->update(['is_default' => true]);
        }
    }

    public function test_a_receipt_template_without_custom_fields_prints_no_custom_box(): void
    {
        $previous = DocumentTemplate::query()
            ->where('type', 'receipt')
            ->where('is_default', true)
            ->first();

        DocumentTemplate::query()
            ->where('type', 'receipt')
            ->update(['is_default' => false]);

        DocumentTemplate::create([
            'name' => 'Operator plain receipt '.uniqid(),
            'type' => 'receipt',
            'style' => 'rail',
            'colors' => ['primary' => '#7a0011', 'accent' => '#059669', 'text' => '#1f2937'],
            'header_html' => null,
            'footer_html' => null,
            'css' => null,
            'is_default' => true,
        ]);

        try {
            $html = ReceiptDocument::salesPayment($this->salesPayment())->render();

            $this->assertStringNotContainsString(
                'custom-html-footer',
                $html,
                'a receipt template with no custom footer must not print a footer box',
            );
        } finally {
            DocumentTemplate::query()->where('name', 'like', 'Operator plain receipt %')->delete();

            $previous?->update(['is_default' => true]);
        }
    }
}
