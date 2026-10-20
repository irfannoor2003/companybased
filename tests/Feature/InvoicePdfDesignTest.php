<?php

namespace Tests\Feature;

use App\Models\DocumentTemplate;
use App\Models\SalesInvoice;
use App\Models\Setting;
use App\Support\DocumentData;
use App\Support\DocumentItems;
use App\Support\PdfLayout;
use Barryvdh\DomPDF\Facade\Pdf;
use Tests\SeedsDatabase;
use Tests\Support\PdfText;
use Tests\TestCase;

/**
 * A representative invoice must render as a real, readable PDF -- not merely produce
 * the right HTML.
 *
 * The redesigned template could satisfy every existing assertion and still be broken
 * in the browser-to-PDF step, because DomPDF resolves layout differently from a
 * browser: it ignores millimetre widths, has no flexbox, and blanks the whole document
 * on certain constructs. Asserting on HTML alone would not catch any of that, so this
 * renders bytes and inspects them.
 *
 * WHAT THIS FILE USED TO ASSERT, AND WHY IT CHANGED
 *
 * It carried a design brief of its own: "no dark rail eating a quarter of the page,
 * no terse abbreviations", plus a hard rule that the page padding must be declared
 * inline. All three describe a standalone invoice template that no longer exists --
 * `sales/invoices/partials/pdf-rail.blade.php` is deleted and the invoice prints from
 * the shared `documents/pdf.blade.php` design like every other document.
 *
 * That change was made for a reason worth keeping: with its own template, the invoice
 * sat outside the template system, so a business could set "Modern Invoice" as its
 * default and watch its invoices come out exactly as before. Every other document
 * type honoured the template; the most-printed document in the system did not, and
 * nothing on screen said so.
 *
 * The intent behind those assertions survives, and is restated below in terms that
 * are actually true of the document a customer holds: no abbreviation, no figure cut
 * off at the page edge, nothing lost to a blanked render. What was dropped is the
 * prescription -- *how* the page is framed is now a template choice, not a property of
 * the invoice.
 */
class InvoicePdfDesignTest extends TestCase
{
    use SeedsDatabase;

    private function realisticInvoice(): SalesInvoice
    {
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
            'number' => 'INV-PREMIUM-001',
            'customer_id' => $customer->id,
            'issue_date' => '2026-10-06',
            'due_date' => '2026-11-05',
            'status' => 'sent',
            'currency' => 'USD',
            'exchange_rate' => 1,
            'subtotal' => '0',
            'tax_amount' => '0',
            'total' => '0',
            'paid_amount' => '0',
            'credit_applied' => '0',
            'credit_note_applied' => '0',
            'notes' => 'Payment is due within 30 days.',
        ]);

        $lines = [
            ['Annual platform licence, 12 users', '12', '1450.00', '0', '8'],
            ['Implementation and onboarding', '1', '4800.00', '10', '8'],
            ['Data migration, legacy system', '1', '1650.00', '15', '8'],
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

        return $invoice->fresh();
    }

    /**
     * The invoice this class renders, built once per test.
     *
     * Memoised because `sales_invoices.number` is unique and several of these tests
     * render it more than once, and because a `setUp()` override here would silently
     * disable `SeedsDatabase` -- the trap the README's maintainer notes describe.
     *
     * An INSTANCE property, not static. `SeedsDatabase` refreshes the database between
     * tests, so a static reference outlives the row it points at and every test after
     * the first one silently gets `null`. That failure is confusing precisely because
     * the first test passes.
     */
    private ?SalesInvoice $invoice = null;

    private function invoice(): SalesInvoice
    {
        return $this->invoice ??= $this->realisticInvoice();
    }

    private function html(?SalesInvoice $invoice = null): string
    {
        return view('documents.pdf', DocumentData::build($invoice ?? $this->invoice()))->render();
    }

    private function pdf(?SalesInvoice $invoice = null): string
    {
        return Pdf::loadHTML($this->html($invoice))->setPaper('a4', 'portrait')->setOptions(pdf_options())->output();
    }

    public function test_a_realistic_invoice_renders_to_a_valid_pdf(): void
    {
        $pdf = $this->pdf();

        $this->assertStringStartsWith('%PDF', $pdf, 'the rendered document is not a PDF');
        $this->assertGreaterThan(
            5000,
            strlen($pdf),
            'a suspiciously small PDF suggests blank pages rather than a rendered document',
        );
    }

    /**
     * The single most important line on the document. If DomPDF clips the sheet the
     * way it used to when the column widths were in millimetres, TOTAL is what falls
     * off the right edge.
     */
    public function test_the_totals_survive_the_render(): void
    {
        $invoice = $this->invoice();

        $html = $this->html();

        $this->assertStringContainsString(
            money($invoice->total, 'USD'),
            $html,
            'the grand total is not in the HTML at all',
        );

        $pdf = $this->pdf();

        $this->assertGreaterThan(5000, strlen($pdf));

        // Read back through the rendered bytes, not the HTML: the clip this guards
        // against happens in the PDF engine, after the markup has already passed.
        $this->assertStringContainsString(
            number_format((float) $invoice->total, 2),
            PdfText::all($pdf),
            'the grand total did not survive the render',
        );
    }

    /**
     * Discount and tax rows are derived from the lines, because the header subtotal
     * has already netted the per-line discounts. Keying the row off the header
     * discount column double-counts and collapses it to zero, which is how the
     * Discount line went missing from the printed invoice.
     */
    public function test_a_discounted_line_prints_a_discount_row(): void
    {
        $this->assertStringContainsString('>Discount<', $this->html(), 'no Discount row was printed');
    }

    /**
     * Every line's figures must be printed. A row that silently fails to render is
     * worse than an obvious error, because the customer reconciles against it.
     *
     * The unit price is asserted as a plain figure rather than through `money()`: the
     * shared design states the currency once, in the meta block, and repeats it on
     * every line otherwise. Asserting the currency here would be asserting a property
     * of the design rather than of the data.
     */
    public function test_every_line_is_printed(): void
    {
        $invoice = $this->invoice();

        $html = $this->html();

        foreach ($invoice->items as $item) {
            $this->assertStringContainsString($item->description, $html);
            $this->assertStringContainsString(
                number_format((float) $item->unit_price, 2),
                $html,
                'a unit price is missing: '.$item->description,
            );
        }
    }

    /**
     * The brief that outlasted the template it was written for: a customer-facing
     * document should not be terse.
     *
     * The old invoice design abbreviated the footer to `P:` and `M:`; the shared design
     * it moved onto abbreviated them too, and this pass spelled them out. That much of
     * the brief is unchanged, and now holds for every document rather than one.
     *
     * The tax number is written here rather than relied on, because the seeded company
     * has none: without it the footer simply omits the row and the assertion would pass
     * for the wrong reason.
     */
    public function test_contact_details_are_spelled_out_not_abbreviated(): void
    {
        Setting::set('company.tax_number', 'NTN-4471902');
        Setting::flushCache();

        $html = $this->html();

        $this->assertStringNotContainsString('P: ', $html, 'contact abbreviations are back');
        $this->assertStringNotContainsString('M: ', $html, 'contact abbreviations are back');

        $this->assertStringContainsString('Phone', $html);
        $this->assertStringContainsString('Email', $html);
        $this->assertStringContainsString('Tax no.', $html);
    }

    /**
     * The invoice's own facts, which the shared template would otherwise drop.
     *
     * A due date and a salesperson are the two things a customer chases an invoice
     * about. They are the reason this was worth doing rather than simply pointing the
     * invoice at the shared view and declaring victory.
     *
     * The salesperson is read from the ORDER, because `sales_invoices` carries no
     * `salesman_id` -- an invoice is raised against an order and the rep is recorded
     * there. Copying it onto the invoice would have been a second answer to "who sold
     * this", free to drift from the first.
     */
    public function test_the_due_date_and_the_salesperson_are_printed(): void
    {
        $invoice = $this->invoice();

        $invoice->update([
            'order_id' => $this->salesOrder(
                $invoice->customer,
                $this->productWithStockItem()[0],
                salesmanId: $this->userForRole('salesman')->id,
            )->id,
        ]);

        $fresh = $invoice->fresh();

        $text = PdfText::all($this->pdf($fresh));

        $this->assertStringContainsString('Due date', $text, 'the due date row is missing');
        $this->assertStringContainsString(
            $fresh->due_date->format('Y-m-d'),
            $text,
            'the due date itself is missing',
        );

        $this->assertStringContainsString('Salesperson', $text, 'the salesperson row is missing');
        $this->assertStringContainsString(
            $fresh->order->salesman->name,
            $text,
            'the salesperson is not the one on the order',
        );
    }

    /**
     * An invoice raised without an order has no rep to name, so the row is omitted
     * rather than printed with an empty value.
     */
    public function test_an_invoice_with_no_order_names_no_salesperson(): void
    {
        $this->assertNull($this->invoice()->order_id);

        $text = PdfText::all($this->pdf());

        $this->assertStringNotContainsString('Salesperson', $text);
    }

    /**
     * An invoice is the only document that is ASKED for money, so it is the only one
     * that states what is still owed.
     */
    public function test_an_unpaid_invoice_states_the_amount_due(): void
    {
        $text = PdfText::all($this->pdf());

        $this->assertStringContainsString('Amount due', $text);

        $this->assertStringContainsString(
            number_format($this->invoice()->balance(), 2),
            $text,
            'the amount due does not agree with the invoice balance',
        );
    }

    /**
     * A settled invoice reads differently from one awaiting money. Printing "Amount
     * due 0.00" on a paid invoice tells the customer to pay for something they have
     * already paid.
     */
    public function test_a_settled_invoice_does_not_ask_for_money(): void
    {
        $invoice = $this->invoice();

        $invoice->update(['status' => 'paid', 'paid_amount' => $invoice->total]);

        $text = PdfText::all($this->pdf($invoice->fresh()));

        $this->assertStringNotContainsString('Amount due', $text, 'a paid invoice still asks for money');
        $this->assertStringContainsString('Paid in full', $text);
    }

    /**
     * Half-paid: the customer owes the remainder, not the whole total.
     */
    public function test_a_part_paid_invoice_states_the_remainder_not_the_total(): void
    {
        $invoice = $this->invoice();

        $invoice->update(['status' => 'partially_paid', 'paid_amount' => 1000]);

        $text = PdfText::all($this->pdf($invoice->fresh()));

        $this->assertStringContainsString('Amount due', $text);
        $this->assertStringContainsString(
            number_format($invoice->fresh()->balance(), 2),
            $text,
            'the amount due is not the balance',
        );
    }

    /**
     * THE REASON FOR THIS CHANGE.
     *
     * An invoice used to print from its own template and never read the template
     * system, so a business could set "Modern Invoice" as its default for the
     * `invoice` type and watch its invoices come out exactly as before. The picker
     * offered a choice it had no effect on.
     */
    public function test_the_invoice_now_honours_the_template_style(): void
    {
        $seen = [];

        foreach ([PdfLayout::STYLE_RAIL, PdfLayout::STYLE_MODERN, PdfLayout::STYLE_MINIMAL] as $style) {
            DocumentTemplate::where('type', 'invoice')->update(['is_default' => false]);

            DocumentTemplate::create([
                'name' => 'Invoice '.$style,
                'type' => 'invoice',
                'style' => $style,
                'layout' => ['style' => $style, 'blocks' => PdfLayout::DEFAULT_BLOCK_ORDER],
                'is_default' => true,
            ]);

            $pdf = $this->pdf();

            $this->assertStringStartsWith('%PDF', $pdf, $style.' did not render');

            $seen[$style] = $pdf;
        }

        $this->assertCount(
            count($seen),
            array_unique($seen),
            'two or more template styles rendered an identical invoice; the template system is not reaching it',
        );

        foreach ($seen as $style => $pdf) {
            $this->assertStringContainsString(
                'INV-PREMIUM-001',
                PdfText::all($pdf),
                $style.' lost the invoice number',
            );
        }
    }

    /**
     * The route an operator actually uses, rather than the renderer called directly --
     * the field is half of what is being tested.
     */
    public function test_the_invoice_pdf_route_uses_the_shared_renderer(): void
    {
        $this->actingAs($this->userForRole('admin'));

        $response = $this->get(route('sales.invoices.pdf', $this->invoice()));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');

        // Pdf::stream() returns a plain Response holding the bytes inline, not a
        // StreamedResponse -- calling streamedContent() on it throws.
        $bytes = (string) $response->getContent();

        $this->assertStringStartsWith('%PDF', $bytes, 'the download route did not return a PDF');
        $this->assertStringContainsString('INV-PREMIUM-001', PdfText::all($bytes));
    }

    /**
     * Guard against the layout traps that silently blank a DomPDF render. Each of
     * these has cost a blank page before.
     */
    public function test_no_constructs_that_blank_a_dompdf_render(): void
    {
        $html = $this->html();

        $this->assertStringNotContainsString('display:flex', $html, 'DomPDF has no flexbox');
        $this->assertStringNotContainsString('display: grid', $html, 'DomPDF has no grid');
        $this->assertStringNotContainsString('var(--', $html, 'DomPDF does not resolve CSS variables');
        $this->assertStringNotContainsString('calc(', $html, 'DomPDF does not resolve calc()');
        $this->assertStringContainsString('table-layout: fixed', $html, 'required for declared widths to hold');
    }

    /**
     * The standalone invoice template is gone, and nothing points at it.
     *
     * Deleting a view that three controllers, a mailable and three test files used to
     * name is only safe if nothing still names it, and a stale `@include` or a stale
     * `view()` call is a 500 on the most-printed document in the system.
     */
    public function test_the_retired_invoice_template_is_gone_and_unreferenced(): void
    {
        $this->assertFileDoesNotExist(resource_path('views/sales/invoices/partials/pdf-rail.blade.php'));

        // These two files are ABOUT the retirement, so they name the deleted path on
        // purpose. Counting them would make the guard impossible to write.
        $aboutTheRetirement = [
            'tests'.DIRECTORY_SEPARATOR.'Feature'.DIRECTORY_SEPARATOR.'InvoicePdfDesignTest.php',
            'tests'.DIRECTORY_SEPARATOR.'Feature'.DIRECTORY_SEPARATOR.'InvoicePdfTemplateTest.php',
        ];

        $offenders = [];

        foreach ([app_path(), resource_path('views'), base_path('tests')] as $root) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

            foreach ($iterator as $file) {
                if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }

                $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());

                if (in_array($relative, $aboutTheRetirement, true)) {
                    continue;
                }

                if (str_contains((string) file_get_contents($file->getPathname()), 'pdf-rail')) {
                    $offenders[] = $relative;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "files still naming the retired invoice template:\n".implode("\n", $offenders),
        );
    }
}
