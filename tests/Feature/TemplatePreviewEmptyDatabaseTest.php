<?php

namespace Tests\Feature;

use App\Models\DocumentTemplate;
use App\Models\User;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * The Preview button must work from the very first install, before any invoice,
 * quote or payment has been recorded. It used to 404 with "No sample document
 * available", which made the whole template editor look broken on the exact
 * database where it matters most -- a new one.
 */
class TemplatePreviewEmptyDatabaseTest extends TestCase
{
    use SeedsDatabase;

    private ?User $admin = null;

    private function admin(): User
    {
        return $this->admin ??= User::where('email', 'admin@nexosdigital.test')->firstOrFail();
    }

    public function test_every_seeded_template_previews_on_an_empty_database(): void
    {
        // The seeder creates templates but no documents, so this is the fresh-install case.
        $this->assertSame(0, \App\Models\PurchaseInvoice::count());

        foreach (DocumentTemplate::orderBy('id')->get() as $template) {
            $response = $this->actingAs($this->admin())
                ->get(route('settings.templates.preview', $template));

            $response->assertOk();
            $response->assertSee('style-'.$template->style, false);
        }
    }

    public function test_a_purchase_invoice_template_previews_as_a_bill_not_a_generic_invoice(): void
    {
        $billTemplate = DocumentTemplate::where('type', 'purchase_invoice')->firstOrFail();

        $html = $this->actingAs($this->admin())
            ->get(route('settings.templates.preview', $billTemplate))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('BILL', strtoupper($html));
        $this->assertStringContainsString('Sample Supplier', $html);
        $this->assertStringContainsString('Sample product or service', $html);
    }

    public function test_a_receipt_template_still_previews_with_the_receipt_shape(): void
    {
        $template = DocumentTemplate::where('type', 'receipt')->firstOrFail();

        $html = $this->actingAs($this->admin())
            ->get(route('settings.templates.preview', $template))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('receipt-amount', $html, 'a receipt has no line-item table');
        $this->assertStringNotContainsString('class="items"', $html);
        $this->assertStringContainsString('Amount received', $html);
    }

    public function test_a_real_document_is_preferred_over_the_synthetic_sample(): void
    {
        $invoice = \App\Models\SalesInvoice::create([
            'number' => 'REAL-INV-1',
            'customer_id' => $this->customer('Real Customer')->id,
            'issue_date' => now()->toDateString(),
            'status' => 'sent',
            'currency' => 'PKR',
            'exchange_rate' => 1,
            'subtotal' => '900.00',
            'tax_amount' => '0.00',
            'total' => '900.00',
        ]);

        $template = DocumentTemplate::where('type', 'invoice')->where('is_default', true)->firstOrFail();

        $html = $this->actingAs($this->admin())
            ->get(route('settings.templates.preview', $template))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('REAL-INV-1', $html);
        $this->assertStringNotContainsString('PREVIEW-0001', $html);
    }
}
