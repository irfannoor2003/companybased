<?php

namespace Tests\Feature;

use App\Models\SalesInvoice;
use App\Support\DocumentData;
use App\Support\DocumentItems;
use Barryvdh\DomPDF\Facade\Pdf;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * Writes a sample invoice PDF to disk so the rendered result can be looked at.
 *
 * Not an assertion suite. The design assertions live in InvoicePdfDesignTest; this
 * exists because every one of those checks passes equally well on a document that
 * looks wrong, and typography and spacing cannot be asserted -- they have to be
 * seen. Skipped unless SAMPLE_PDF_PATH is set, so it never writes anywhere during
 * a normal suite run.
 */
class InvoicePdfSampleTest extends TestCase
{
    use SeedsDatabase;

    public function test_it_writes_a_sample_invoice_pdf(): void
    {
        $path = getenv('SAMPLE_PDF_PATH');

        if (! $path) {
            $this->markTestSkipped('Set SAMPLE_PDF_PATH to write a sample invoice for visual review.');
        }

        $customer = $this->customer('Northwind Traders Ltd', 'ap@northwind.test');
        $customer->update([
            'contact_name' => 'Dana Whitfield',
            'phone' => '+1 415 555 0142',
            'address' => "1280 Shipwright Way\nSuite 400",
            'city' => 'Oakland',
            'country' => 'USA',
            'tax_number' => 'US-8841-2290',
        ]);

        $invoice = SalesInvoice::create([
            'number' => 'INV-2026-1048',
            'customer_id' => $customer->id,
            'issue_date' => '2026-10-06',
            'due_date' => '2026-11-05',
            'status' => 'sent',
            'currency' => 'USD',
            'exchange_rate' => 1,
            'subtotal' => '0', 'tax_amount' => '0', 'total' => '0',
            'paid_amount' => '0', 'credit_applied' => '0', 'credit_note_applied' => '0',
            'notes' => 'Payment is due within 30 days of the issue date. Please quote the invoice '
                .'number on the remittance advice. Late settlement is subject to the agreed '
                .'terms of trade.',
        ]);

        $lines = [
            ['Annual platform licence, 12 named users', '12', '1450.00', '0', '8'],
            ['Implementation, configuration and onboarding', '1', '4800.00', '10', '8'],
            ['Data migration from legacy system', '1', '1650.00', '15', '8'],
            ['Priority support, quarterly retainer', '4', '320.00', '0', '8'],
        ];

        $subtotal = 0.0;
        $tax = 0.0;

        foreach ($lines as [$description, $qty, $price, $discountPercent, $taxPercent]) {
            $f = DocumentItems::lineTotals((float) $qty, (float) $price, (float) $discountPercent, (float) $taxPercent);

            $invoice->items()->create([
                'product_id' => null,
                'description' => $description,
                'qty' => $qty,
                'unit_price' => $price,
                'discount_percent' => $discountPercent,
                'tax_percent' => $taxPercent,
                'line_total' => (string) round($f['line_net'] + $f['line_tax'], 2),
            ]);

            $subtotal += $f['line_net'];
            $tax += $f['line_tax'];
        }

        $invoice->update([
            'subtotal' => (string) $subtotal,
            'tax_amount' => (string) $tax,
            'total' => (string) round($subtotal + $tax, 2),
        ]);

        $html = view('documents.pdf', DocumentData::build($invoice))->render();

        file_put_contents($path, Pdf::loadHTML($html)->setPaper('a4', 'portrait')->setOptions(pdf_options())->output());

        $this->assertFileExists($path);
    }
}
