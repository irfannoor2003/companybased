<?php

namespace Tests\Feature;

use App\Models\DocumentTemplate;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\SalesPayment;
use App\Models\SupplierPayment;
use App\Models\WithholdingTaxReceipt;
use App\Support\DocumentData;
use App\Support\PdfLayout;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * A proof of payment must not be a second-class document: it prints from the
 * same renderer, obeys the same template (style, colours, blocks, header and
 * footer) and shows the same on-screen design as the invoice it settles.
 *
 * These tests pin the unification, not the wording -- the wording tests live in
 * ReceiptPdfConsolidationTest and still hold.
 */
class ReceiptSharedDesignTest extends TestCase
{
    use SeedsDatabase;

    private function payment(): SalesPayment
    {
        return SalesPayment::create([
            'number' => 'RCPT-SHARED-1',
            'invoice_id' => SalesInvoice::create([
                'number' => 'INV-SHARED-1',
                'customer_id' => $this->customer('Shared Co')->id,
                'issue_date' => now()->subDays(3)->toDateString(),
                'status' => 'sent',
                'currency' => 'PKR',
                'exchange_rate' => 1,
                'subtotal' => '1000.00',
                'tax_amount' => '0.00',
                'total' => '1000.00',
            ])->id,
            'customer_id' => $this->customer('Shared Co')->id,
            'amount' => '1000.00',
            'payment_date' => now()->subDay()->toDateString(),
            'method' => 'bank_transfer',
            'currency' => 'PKR',
            'exchange_rate' => 1,
        ]);
    }

    public function test_receipts_render_from_the_shared_document_pipeline(): void
    {
        $data = DocumentData::build($this->payment());

        $this->assertNotEmpty($data['receipt'], 'a receipt carries the amount panel');
        $this->assertSame(1000.0, (float) $data['receipt']['amount']);
        $this->assertSame('Received from', $data['receipt']['partyLabel']);
        $this->assertFalse($data['blockReady']['items'], 'a receipt has no line-item table');
        $this->assertFalse($data['blockReady']['totals'], 'a receipt has no invoice totals');
        $this->assertSame([], $data['rows']);
        $this->assertSame([], $data['columns']);

        $html = view('documents.pdf', $data)->render();
        $this->assertStringContainsString('RCPT-SHARED-1', $html);
        $this->assertStringContainsString('Amount received', $html);
        $this->assertStringContainsString('receipt-amount', $html, 'the amount panel renders, not an empty item table');
    }

    public function test_a_receipt_follows_its_templates_style(): void
    {
        $payment = $this->payment();
        $previous = DocumentTemplate::where('type', 'receipt')->where('is_default', true)->first();

        DocumentTemplate::where('type', 'receipt')->update(['is_default' => false]);
        DocumentTemplate::create([
            'name' => 'Modern receipt '.uniqid(),
            'type' => 'receipt',
            'style' => PdfLayout::STYLE_MODERN,
            'colors' => ['primary' => '#7a0011', 'accent' => '#059669', 'text' => '#1f2937'],
            'header_html' => '<p>RECEIPT HEADER MARKER</p>',
            'footer_html' => '<p>RECEIPT FOOTER MARKER</p>',
            'is_default' => true,
        ]);

        try {
            $html = view('documents.pdf', DocumentData::build($payment))->render();

            $this->assertStringContainsString('style-modern', $html, 'the template style reaches the receipt');
            $this->assertStringContainsString('#7a0011', $html, 'the template colour wins');
            $this->assertStringContainsString('RECEIPT HEADER MARKER', $html, 'the operator header prints');
            $this->assertStringContainsString('RECEIPT FOOTER MARKER', $html, 'the operator footer prints');
            $this->assertStringNotContainsString('class="items"', $html, 'no empty item table is printed');
        } finally {
            DocumentTemplate::where('name', 'like', 'Modern receipt %')->delete();
            $previous?->update(['is_default' => true]);
        }
    }

    public function test_the_standalone_receipt_template_is_gone(): void
    {
        $this->assertFileDoesNotExist(resource_path('views/partials/pdf/receipt.blade.php'));
        $this->assertFileExists(resource_path('views/documents/blocks/canvas/receipt.blade.php'));
    }

    public function test_the_receipt_template_previews_with_a_real_payment(): void
    {
        $this->payment(); // the preview needs at least one sample payment
        $template = DocumentTemplate::where('type', 'receipt')->firstOrFail();
        $admin = \App\Models\User::where('email', 'admin@nexosdigital.test')->firstOrFail();

        $response = $this->actingAs($admin)->get(route('settings.templates.preview', $template));

        $response->assertOk();
        $response->assertSee('receipt-amount', false);
    }

    public function test_all_receipt_controllers_render_the_shared_design(): void
    {
        $admin = \App\Models\User::where('email', 'admin@nexosdigital.test')->firstOrFail();
        $this->actingAs($admin);

        $cases = [
            [$this->payment(), 'sales.sales_payments', 'Amount received'],
            [$this->supplierPayment(), 'suppliers.supplier_payments', 'Amount paid'],
            [$this->withholdingReceipt(), 'sales.withholding_tax_receipts', 'Tax deducted'],
        ];

        foreach ($cases as [$document, $routePrefix, $label]) {
            $this->get(route($routePrefix.'.show', $document))
                ->assertOk()
                ->assertSee($label, false);

            $pdf = $this->get(route($routePrefix.'.pdf', $document));
            $pdf->assertOk();
            $this->assertStringStartsWith('%PDF', $pdf->getContent());
        }
    }

    private function supplierPayment(): SupplierPayment
    {
        $supplier = $this->supplier('Shared Vendor');

        $bill = PurchaseInvoice::create([
            'number' => 'PIN-SHARED-1',
            'supplier_id' => $supplier->id,
            'issue_date' => now()->subDays(3)->toDateString(),
            'status' => 'sent',
            'currency' => 'PKR',
            'exchange_rate' => 1,
            'subtotal' => '500.00',
            'tax_amount' => '0.00',
            'total' => '500.00',
        ]);

        return SupplierPayment::create([
            'number' => 'SP-SHARED-1',
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
        $invoice = SalesInvoice::create([
            'number' => 'INV-SHARED-WHT-1',
            'customer_id' => $this->customer('Shared Co')->id,
            'issue_date' => now()->subDays(3)->toDateString(),
            'status' => 'sent',
            'currency' => 'PKR',
            'exchange_rate' => 1,
            'subtotal' => '150.00',
            'tax_amount' => '0.00',
            'total' => '150.00',
        ]);

        return WithholdingTaxReceipt::create([
            'number' => 'WHT-SHARED-1',
            'customer_id' => $this->customer('Shared Co')->id,
            'invoice_id' => $invoice->id,
            'receipt_date' => now()->subDay()->toDateString(),
            'amount' => '150.00',
            'tax_rate_percent' => '15.00',
            'tax_amount' => '150.00',
            'currency' => 'PKR',
        ]);
    }
}
