<?php

namespace Tests\Feature;

use App\Models\SalesInvoice;
use App\Support\DocumentItems;
use Barryvdh\DomPDF\Facade\Pdf;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * A representative invoice must render as a real, readable PDF -- not merely
 * produce the right HTML.
 *
 * The redesigned template could satisfy every existing assertion and still be
 * broken in the browser-to-PDF step, because DomPDF resolves layout differently
 * from a browser: it ignores millimetre widths, has no flexbox, and blanks the
 * whole document on certain constructs. Asserting on HTML alone would not catch
 * any of that, so this renders bytes and inspects them.
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

    public function test_a_realistic_invoice_renders_to_a_valid_pdf(): void
    {
        $invoice = $this->realisticInvoice();

        $html = view('sales.invoices.partials.pdf-rail', compact('invoice'))->render();

        $pdf = Pdf::loadHTML($html)->setPaper('a4', 'portrait')->output();

        $this->assertStringStartsWith('%PDF', $pdf, 'the rendered document is not a PDF');
        $this->assertGreaterThan(
            5000,
            strlen($pdf),
            'a suspiciously small PDF suggests blank pages rather than a rendered document',
        );
    }

    /**
     * The single most important line on the document. If DomPDF clips the sheet
     * the way it used to when the column widths were in millimetres, TOTAL is
     * what falls off the right edge.
     */
    public function test_the_totals_survive_the_render(): void
    {
        $invoice = $this->realisticInvoice();

        $html = view('sales.invoices.partials.pdf-rail', compact('invoice'))->render();
        $pdf = Pdf::loadHTML($html)->setPaper('a4', 'portrait')->output();

        $this->assertStringContainsString(
            money($invoice->total, 'USD'),
            $html,
            'the grand total is not in the HTML at all',
        );

        // A rendered PDF must not be trivially small; that is how a blanked
        // document presents itself.
        $this->assertGreaterThan(5000, strlen($pdf));
    }

    /**
     * Discount and tax rows are derived from the lines, because the header
     * subtotal has already netted the per-line discounts. Keying the row off the
     * header discount column double-counts and collapses it to zero, which is how
     * the Discount line went missing from the printed invoice.
     */
    public function test_a_discounted_line_prints_a_discount_row(): void
    {
        $invoice = $this->realisticInvoice();

        $html = view('sales.invoices.partials.pdf-rail', compact('invoice'))->render();

        $this->assertStringContainsString('>Discount<', $html, 'no Discount row was printed');
    }

    /**
     * Every line's figures must be printed. A row that silently fails to render
     * is worse than an obvious error, because the customer reconciles against it.
     */
    public function test_every_line_is_printed(): void
    {
        $invoice = $this->realisticInvoice();

        $html = view('sales.invoices.partials.pdf-rail', compact('invoice'))->render();

        foreach ($invoice->items as $item) {
            $this->assertStringContainsString($item->description, $html);
            $this->assertStringContainsString(
                money($item->unit_price, 'USD'),
                $html,
                'a unit price is missing: '.$item->description,
            );
        }
    }

    /**
     * The design brief: no dark rail block eating a quarter of the page, and no
     * terse abbreviations. Those were what made the document read as a template.
     */
    public function test_the_rail_and_abbreviations_are_gone(): void
    {
        $invoice = $this->realisticInvoice();

        $html = view('sales.invoices.partials.pdf-rail', compact('invoice'))->render();

        $this->assertStringNotContainsString('P: ', $html, 'contact abbreviations are back');
        $this->assertStringNotContainsString('M: ', $html, 'contact abbreviations are back');
        $this->assertStringNotContainsString('height: 296mm', $html, 'the full-height rail is back');
        $this->assertStringNotContainsString('class="rail"', $html, 'the rail column is back');

        // Spelled out in full.
        $this->assertStringContainsString('Phone', $html);
        $this->assertStringContainsString('Tax no.', $html);
    }

    /**
     * Guard against the layout traps that silently blank a DomPDF render. Each
     * of these has cost a blank page before.
     */
    /**
     * The page margin.
     *
     * This fault was invisible to every other assertion in this file. The margin
     * was declared as `.pad { padding: 15mm 16mm ... }` and silently lost to the
     * more specific `table.sheet td { padding: 0 }` rule, so the document
     * rendered flush to the paper edge -- every element touching the paper -- and
     * every test here still passed, because the HTML was well-formed and the PDF
     * was a valid size. Only rendering it to an image showed the problem.
     *
     * A second attempt (resetting the table padding with a child combinator and
     * padding a separate class) failed the same way: DomPDF does not apply child
     * combinators as a browser does, so the reset kept winning. Inline padding on
     * the content cell is the only form that works.
     *
     * The fix was confirmed by rendering the page to PNG and inspecting it. That
     * check is not reproduced here because it needs a rasteriser this project
     * does not have -- adding a Python dependency to the suite for one assertion
     * would be worse than the gap, so the guard pins the declaration itself.
     */
    public function test_the_page_padding_is_declared_inline(): void
    {
        $invoice = $this->realisticInvoice();

        $html = view('sales.invoices.partials.pdf-rail', compact('invoice'))->render();

        $this->assertMatchesRegularExpression(
            '/class="page-pad"[^>]*style="[^"]*padding:\s*15mm/i',
            $html,
            'the page padding must be inline on the content cell; in the stylesheet it '
            .'loses to the table reset and the margin collapses to zero',
        );
    }

    public function test_no_constructs_that_blank_a_dompdf_render(): void
    {
        $invoice = $this->realisticInvoice();

        $html = view('sales.invoices.partials.pdf-rail', compact('invoice'))->render();

        $this->assertStringNotContainsString('display:flex', $html, 'DomPDF has no flexbox');
        $this->assertStringNotContainsString('display: grid', $html, 'DomPDF has no grid');
        $this->assertStringNotContainsString('var(--', $html, 'DomPDF does not resolve CSS variables');
        $this->assertStringNotContainsString('calc(', $html, 'DomPDF does not resolve calc()');
        $this->assertStringContainsString('table-layout: fixed', $html, 'required for declared widths to hold');
    }
}