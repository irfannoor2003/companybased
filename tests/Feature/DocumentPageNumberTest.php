<?php

namespace Tests\Feature;

use App\Models\SalesQuote;
use App\Support\DocumentData;
use Barryvdh\DomPDF\Facade\Pdf;
use Tests\SeedsDatabase;
use Tests\Support\PdfText;
use Tests\TestCase;

/**
 * Every page of a printed document carries its page number.
 *
 * Page numbers were absent by design: the template asserted that DomPDF cannot
 * render them once `@page { margin: 0 }` is set, which the full-bleed rail
 * requires. That is true of `@page` margin boxes -- measured, every margin-box
 * variant drew nothing on any page -- but it was generalised into "page numbers
 * are impossible here".
 *
 * They are not. DomPDF re-emits a `position: fixed` block on every page, the same
 * mechanism the rail already relies on, and honours `counter(page)` in its
 * generated content. The numbering is therefore drawn exactly the way the rail is.
 *
 * "Page N of M" is deliberately NOT used: DomPDF resolves `counter(pages)` to 0
 * because the total is unknown while pages are being laid out, so it printed
 * "Page 3 of 0". A wrong total is worse than no total.
 *
 * Rendered through `documents.pdf`, which serves quotes, orders, delivery notes,
 * credit notes, recurring invoices and the purchase-side documents. The sales
 * invoice is NOT one of them -- it has its own template, because it carries a
 * credit_applied mechanic the others have no equivalent of.
 */
class DocumentPageNumberTest extends TestCase
{
    use SeedsDatabase;

    /**
     * Render a quote with $lineCount lines and return the raw PDF.
     */
    private function renderQuote(int $lineCount): string
    {
        $customer = $this->customer('Northwind Traders Ltd', 'ap@northwind.test');

        $quote = SalesQuote::create([
            'number' => 'QUO-PAGE-0001',
            'customer_id' => $customer->id,
            'issue_date' => '2026-10-06',
            'expiry_date' => '2026-11-05',
            'status' => 'draft',
            'currency' => 'USD',
            'exchange_rate' => 1,
            'subtotal' => '0', 'tax_amount' => '0', 'total' => '0',
        ]);

        $lineCount = max(1, $lineCount);

        for ($i = 1; $i <= $lineCount; $i++) {
            $quote->items()->create([
                'product_id' => null,
                'description' => sprintf('Line item %03d — a description long enough to wrap once inside the table cell', $i),
                'qty' => 1,
                'unit_price' => 10.00,
                'discount_percent' => 0,
                'tax_percent' => 0,
                'sort_order' => $i,
            ]);
        }

        $html = view('documents.pdf', DocumentData::build($quote))->render();

        return Pdf::loadHTML($html)
            ->setPaper('a4', 'portrait')
            ->setOptions(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'defaultFont' => 'DejaVu Sans'])
            ->output();
    }

    /**
     * The extractor has to be trustworthy before any assertion resting on it means
     * anything: it reads the quote's own text back out of the rendered PDF.
     */
    public function test_the_pdf_text_extractor_reads_a_rendered_document(): void
    {
        $text = PdfText::all($this->renderQuote(3));

        $this->assertStringContainsString('QUO-PAGE-0001', $text, 'the extractor could not read the document number back');
        $this->assertStringContainsString('Northwind Traders Ltd', $text);
        $this->assertStringContainsString('Line item 001', $text);
    }

    public function test_a_single_page_document_is_numbered(): void
    {
        $pages = PdfText::pages($this->renderQuote(2));

        $this->assertCount(1, $pages, 'a two-line quote should be one page');
        $this->assertStringContainsString('Page 1', $pages[0]);
    }

    /**
     * The real requirement: every page, not just the first.
     *
     * The numbering is a fixed block precisely because DomPDF re-emits it per page.
     * A `position: absolute` block would pass the single-page test and then print on
     * page 1 only.
     */
    public function test_every_page_of_a_multi_page_document_is_numbered(): void
    {
        $pages = PdfText::pages($this->renderQuote(80));

        $this->assertGreaterThan(2, count($pages), '80 lines should span several pages, so this really tests repetition');

        foreach ($pages as $index => $pageText) {
            $this->assertStringContainsString(
                'Page '.($index + 1),
                $pageText,
                'page '.($index + 1).' is missing its page number',
            );
        }
    }

    /**
     * The rail has to survive on every page too. The page number is a second fixed
     * block, and the earlier rail regression was caused by exactly that kind of
     * addition: a fixed band left in place beside the rail made DomPDF re-emit the
     * whole page-1 layout and push a document from two pages to three.
     */
    public function test_adding_page_numbers_does_not_disturb_the_rail_or_the_page_count(): void
    {
        $pages = PdfText::pages($this->renderQuote(80));

        foreach ($pages as $index => $pageText) {
            $this->assertStringContainsString(
                'Northwind Traders Ltd',
                $pageText,
                'the rail lost the company name on page '.($index + 1),
            );
        }
    }

    /**
     * The numbering must not sit on top of the content it is meant to accompany.
     */
    public function test_the_page_number_does_not_replace_the_content(): void
    {
        $pages = PdfText::pages($this->renderQuote(6));

        foreach ($pages as $index => $pageText) {
            $this->assertStringContainsString('QUO-PAGE-0001', $pageText, 'the document identity is missing on page '.($index + 1));
            $this->assertStringContainsString('Page '.($index + 1), $pageText);
        }
    }

    /**
     * `counter(pages)` is not usable here, so guard against anyone reintroducing
     * "of M" and shipping a document that reads "Page 2 of 0".
     */
    public function test_the_page_number_does_not_claim_a_total_page_count(): void
    {
        $text = PdfText::all($this->renderQuote(80));

        $this->assertDoesNotMatchRegularExpression(
            '/Page\s+\d+\s+of\s+\d+/',
            $text,
            'DomPDF resolves counter(pages) to 0, so "of M" must not be printed',
        );
    }
}
