<?php

namespace Tests\Feature;

use App\Models\DocumentTemplate;
use App\Models\SalesInvoice;
use App\Support\DocumentData;
use App\Support\PdfLayout;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * A pasted 140-character code as a line description used to occupy the whole
 * row: a single-line input could not wrap, the show table forced nowrap, and
 * DomPDF had no break instruction, so Qty and Unit price disappeared off the
 * right of the page in every template.
 */
class LongDescriptionLayoutTest extends TestCase
{
    use SeedsDatabase;

    private string $long = 'oikmsnadlkndkasndklasndkasndklasndklansldkanskldnalksndlaksndlaksndlaksndlasndlasndlaksndlaosdkalsdkadlkajdkalskdmlam';

    public function test_the_form_uses_wrapping_textareas_for_descriptions(): void
    {
        $user = \App\Models\User::where('email', 'admin@nexosdigital.test')->firstOrFail();

        foreach (['/sales/invoices/create', '/sales/quotes/create', '/sales/delivery-notes/create', '/suppliers/purchase-invoices/create'] as $path) {
            $html = $this->actingAs($user)->get($path)->assertOk()->getContent();

            $this->assertStringContainsString(
                ':name="\'items[\' + i + \'][description]\'"',
                $html,
                $path.' does not render the line description field',
            );
            $this->assertStringContainsString('break-all', $html, $path.' description cannot wrap long words');
            $this->assertStringNotContainsString(
                '<input type="text" :name="\'items[\' + i + \'][description]\'"',
                $html,
                $path.' still uses a single-line input for descriptions',
            );
        }
    }

    public function test_the_show_page_wraps_the_description_without_hiding_numeric_columns(): void
    {
        $invoice = $this->invoiceWithLongLine();
        $user = \App\Models\User::where('email', 'admin@nexosdigital.test')->firstOrFail();

        $html = $this->actingAs($user)
            ->get(route('sales.invoices.show', $invoice))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($this->long, $html, 'the full description must still be shown');
        $this->assertMatchesRegularExpression(
            '/class="[^"]*!whitespace-normal break-all[^"]*"[^>]*>'.preg_quote($this->long, '/').'/',
            $html,
            'the description cell must override the table-wide nowrap',
        );

        // table-fixed needs an explicit width: with width:auto Chrome sizes the
        // table to the long word (1334px) and scrolls Qty/price off the paper.
        $this->assertStringContainsString('table-base table-fixed w-full', $html);

        // The print stylesheet must pin tables to the page and force wrapping.
        $layout = (string) file_get_contents(resource_path('views/layouts/app.blade.php'));
        $this->assertStringContainsString('width: 100% !important', $layout);
        $this->assertStringContainsString('overflow-wrap: anywhere !important', $layout);
        $this->assertStringContainsString('.table-base td.text-right, .table-base td.num', $layout);
        $this->assertStringContainsString('white-space: nowrap !important', $layout, 'money figures must never break across lines');
    }

    public function test_every_pdf_template_wraps_long_descriptions_and_keeps_the_full_text(): void
    {
        $invoice = $this->invoiceWithLongLine();
        $invoice->load(['customer', 'items.product']);

        foreach (DocumentTemplate::all() as $template) {
            $data = DocumentData::build($invoice);
            $data['layout'] = PdfLayout::forTemplate($template);

            $html = view('documents.pdf', $data)->render();

            $this->assertStringContainsString(
                $this->long,
                $html,
                $template->name.' truncated the description instead of printing it in full',
            );
            $this->assertStringContainsString(
                'word-wrap: break-word',
                $html,
                $template->name.' has no wrap instruction for a long unbroken description',
            );
            $this->assertStringContainsString('33%', $html, $template->name.' lost the fixed description column width');
        }
    }

    private function invoiceWithLongLine(): SalesInvoice
    {
        $invoice = SalesInvoice::create([
            'number' => 'INV-LONG-'.uniqid(),
            'customer_id' => $this->customer('Long Line Co')->id,
            'issue_date' => now()->toDateString(),
            'status' => 'sent',
            'currency' => 'PKR',
            'exchange_rate' => 1,
            'subtotal' => '1000.00',
            'tax_amount' => '0.00',
            'total' => '1000.00',
        ]);

        \App\Models\SalesInvoiceItem::create([
            'invoice_id' => $invoice->id,
            'description' => $this->long,
            'qty' => 2,
            'unit_price' => 500,
            'discount_percent' => 0,
            'tax_percent' => 0,
        ]);

        return $invoice;
    }
}
