<?php

namespace Tests\Feature;

use App\Models\SalesInvoice;
use App\Models\SalesInvoiceItem;
use App\Support\DocumentData;
use App\Support\InvoiceTemplate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * The four invoice templates used to be four unrelated stylesheets. They drifted:
 * the header rule, the date format, the totals block and the balance wording all
 * differed between them, and each render path computed its line totals in its own
 * slightly different way.
 *
 * They are now thin skins over one skeleton. These tests prove each template
 * still renders a real PDF, that they share the skeleton, and that the per-line
 * arithmetic is rounded so the printed rows add up to the printed total.
 */
class InvoicePdfTemplateTest extends TestCase
{
    use SeedsDatabase;

    private function invoice(): SalesInvoice
    {
        $invoice = SalesInvoice::create([
            'number' => 'INV-'.uniqid(),
            'reference_number' => 'INV-OS-4471',
            'customer_id' => $this->customer()->id,
            'issue_date' => now()->subDays(3)->toDateString(),
            'due_date' => now()->addDays(27)->toDateString(),
            'status' => 'sent',
            'currency' => 'USD',
            'exchange_rate' => 1,
            'subtotal' => '1000.00',
            'tax_amount' => '170.00',
            'total' => '1170.00',
            'paid_amount' => '200.00',
        ]);

        SalesInvoiceItem::create([
            'invoice_id' => $invoice->id,
            'description' => 'Consulting',
            'qty' => '10',
            'unit_price' => '65.00',
            'discount_percent' => '0',
            'tax_percent' => '17',
            'line_total' => '760.50',
        ]);

        SalesInvoiceItem::create([
            'invoice_id' => $invoice->id,
            'description' => 'Support retainer',
            'qty' => '1',
            'unit_price' => '250.00',
            'discount_percent' => '0',
            'tax_percent' => '0',
            'line_total' => '250.00',
        ]);

        return $invoice->fresh();
    }

    public function test_every_template_name_renders_the_same_design(): void
    {
        $views = array_map(
            fn (string $template): string => InvoiceTemplate::view($template),
            InvoiceTemplate::all(),
        );

        // The names are still accepted so stored invoices and the existing
        // switcher keep working, but they no longer select a different layout.
        // Four names that render four different documents is how the same
        // invoice came to look different depending on an office worker's choice.
        $this->assertCount(
            1,
            array_unique($views),
            'all template names must resolve to one design, got: '.implode(', ', $views),
        );
    }

    public function test_an_unknown_template_name_still_renders(): void
    {
        // A bad value can be stored on an invoice; it must not break the PDF.
        foreach (['does-not-exist', ''] as $stored) {
            $view = InvoiceTemplate::view($stored);

            $this->assertFileExists(
                resource_path('views/'.str_replace('.', '/', $view).'.blade.php'),
                "a stored template of '{$stored}' must still resolve to a real view",
            );
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function templates(): array
    {
        return [
            'classic' => ['classic'],
            'modern' => ['modern'],
            'minimal' => ['minimal'],
            'corporate' => ['corporate'],
        ];
    }

    #[DataProvider('templates')]
    public function test_each_template_renders_a_pdf(string $template): void
    {
        $invoice = $this->invoice();
        $invoice->update(['template' => $template]);

        $response = $this->actingAs($this->userForRole('Admin'))
            ->get("/sales/invoices/{$invoice->id}/pdf")
            ->assertOk();

        // Pdf::stream() returns a plain Response holding the bytes inline, not a
        // StreamedResponse — calling streamedContent() on it throws.
        $this->assertStringStartsWith(
            '%PDF',
            $response->getContent(),
            "The {$template} template did not produce a PDF.",
        );

        $this->assertGreaterThan(
            1000,
            strlen((string) $response->getContent()),
            "The {$template} template produced a suspiciously small PDF; a blank page is the classic symptom.",
        );
    }

    public function test_the_document_lives_in_one_shared_template(): void
    {
        // One design, one file -- and now one design for EVERY document type, not
        // just the invoice. The four old per-template stylesheets are gone rather
        // than kept as skins: they were independently authored, which is how they
        // came to disagree about the header rule, the date format and the totals
        // block on the same invoice.
        $this->assertFileExists(resource_path('views/documents/pdf.blade.php'));

        foreach ([
            'views/sales/invoices/partials/pdf-rail.blade.php',
            'views/sales/invoices/partials/pdf-modern.blade.php',
            'views/sales/invoices/partials/pdf-minimal.blade.php',
            'views/sales/invoices/partials/pdf-classic.blade.php',
            'views/sales/invoices/partials/pdf-corporate.blade.php',
        ] as $retired) {
            $this->assertFileDoesNotExist(
                resource_path($retired),
                "{$retired} is back; it must not drift from the live design again.",
            );
        }
    }

    public function test_the_totals_block_is_a_single_table(): void
    {
        $html = view('documents.pdf', DocumentData::build($this->invoice()))->render();

        // The template wrapped the shared _totals partial in a <table> that the
        // partial already emitted, producing a nested table. The total was still
        // arithmetically right, so no assertion caught it — only a person looking
        // at the PDF would see the spacing was wrong.
        $this->assertSame(
            1,
            substr_count($html, 'class="totals"'),
            'the totals block must be one table, not a table inside a table',
        );

        $this->assertStringNotContainsString(
            'class="due due"',
            $html,
            'a duplicated class means the same modifier is applied twice',
        );
    }

    public function test_the_totals_block_shows_the_discount(): void
    {
        // subtotal is stored net of the line discounts. Printing it and calling it
        // "Subtotal" dropped the discount from the document entirely, so the PDF
        // and the invoice screen showed different subtotals for the same invoice
        // with nothing to reconcile them.
        $invoice = $this->invoice();
        $invoice->items()->update(['discount_percent' => 10]);

        // The header has to move too, exactly as DocumentItems::sync() does when a
        // discount is entered. Mutating the lines alone leaves subtotal at the
        // undiscounted figure, which is not a state the application can produce.
        $invoice->update([
            'subtotal' => '810.00',   // 900 gross less 10% off each line
            'tax_amount' => '137.70', // 17% of the discounted net
            'total' => '947.70',
        ]);

        $html = view('documents.pdf', DocumentData::build($invoice->fresh()))->render();

        $this->assertStringContainsString('Discount', $html);
        $this->assertStringContainsString('90.00', $html, 'the discount amount must be shown');
    }

    public function test_line_totals_are_rounded_to_two_places(): void
    {
        $invoice = $this->invoice();

        $html = view('documents.pdf', DocumentData::build($invoice))->render();

        // 10 x 65.00 with 17% tax = 760.50. Unrounded floats are what leave the
        // printed rows not adding up to the printed total.
        $this->assertStringContainsString('65.00', $html, 'the unit price is printed as entered');
        $this->assertStringContainsString('760.50', $html, 'the line total is rounded to 2dp');
        $this->assertStringContainsString('110.50', $html, 'the line tax is rounded to 2dp');
    }

    public function test_the_template_shows_both_numbers_and_the_balance(): void
    {
        $invoice = $this->invoice();

        $html = view('documents.pdf', DocumentData::build($invoice))->render();

        $this->assertStringContainsString($invoice->number, $html, 'the system number is the primary identifier');
        $this->assertStringContainsString('INV-OS-4471', $html, 'the manual number must reach the document');

        // The hero figure is the invoice total, with the balance stated
        // separately. Showing the balance alone put "PKR 0.00" in the largest
        // type on a fully paid invoice, which reads as an error rather than as
        // a settled bill.
        // The totals block is labelled "Total" and "Amount due". The test is about the
        // numbers being present and correctly balanced, not about one particular
        // wording of the row captions.
        $this->assertStringContainsString('Total', $html);
        $this->assertStringContainsString('Amount due', $html);
        $this->assertStringContainsString('1,170.00', $html);
    }

    public function test_the_template_is_self_contained(): void
    {
        // DomPDF cannot resolve a Blade layout, @vite or a Blade component, so a
        // template that reaches for one renders blank or throws.
        $html = view('documents.pdf', DocumentData::build($this->invoice()))->render();

        foreach (['@extends', '@vite', 'x-app-layout', '<x-', '@stack', '@push'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html, "PDF template must not contain {$forbidden}");
        }

        $this->assertStringContainsString('<!DOCTYPE html>', $html);
    }
}
