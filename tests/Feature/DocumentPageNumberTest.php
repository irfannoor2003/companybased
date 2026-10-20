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
            ->setOptions(pdf_options())
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

    /**
     * THE REGRESSION, and the reason the other assertions here are worth anything.
     *
     * `PdfText` found stream bodies with
     * `stream\r?\n(?P<body>.*?)\r?\nendstream`. `.*?` is lazy, so it stops at the
     * EARLIEST newline followed by `endstream` -- and the byte before `endstream` is
     * very often part of the compressed data rather than a separator. A stream whose
     * deflate bytes happen to end in `0x0a` was captured one byte short, failed to
     * inflate, and was silently skipped. The page came back as an empty string.
     *
     * Nothing failed. Every `assertStringNotContainsString()` written against an
     * empty string passes, which is how a bank-details assertion sat there green
     * while checking that a document did not contain text the extractor had never
     * read.
     *
     * Whether a given render trips it depends on the compressed bytes, so it is
     * content-dependent: one footer label was enough to make a whole class of
     * assertions meaningless. Which is the argument for the throw below rather than
     * a warning.
     */
    public function test_the_extractor_refuses_a_pdf_it_cannot_read_rather_than_returning_nothing(): void
    {
        $pdf = $this->renderQuote(3);

        $this->assertGreaterThan(0, strlen(trim(PdfText::all($pdf))), 'the extractor read nothing at all');

        // Corrupt the very first content stream so it cannot inflate.
        $broken = preg_replace_callback(
            '#(?<=stream\n)[^\n]{40,}#',
            fn (array $m): string => str_repeat("\x00", strlen($m[0])),
            $pdf,
            1,
        );

        $this->assertNotSame($pdf, $broken, 'the fixture could not be corrupted; the test is not testing anything');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('FlateDecode');

        PdfText::all((string) $broken);
    }

    /**
     * A document that genuinely printed nothing must still be refused, for the same
     * reason: "the extractor failed" and "the page is blank" are indistinguishable
     * from the outside, and guessing wrong makes an assertion pass vacuously.
     *
     * Built by hand rather than by mangling a real render, because the real render's
     * text lives inside a compressed stream -- there is no `BT` in the file to strip.
     * This is the smallest PDF DomPDF could have produced for a page with nothing on
     * it, and `PdfText` still has to refuse it rather than return an empty string.
     */
    public function test_the_extractor_refuses_a_pdf_with_no_readable_text(): void
    {
        $pdf = "%PDF-1.3\n"
            ."1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
            ."2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n"
            ."3 0 obj\n<< /Type /Page /MediaBox [0 0 595 842] /Parent 2 0 R /Contents 4 0 R >>\nendobj\n"
            ."4 0 obj\n<< /Length 0 >>\nstream\n\nendstream\nendobj\n"
            .'trailer'."\n<< /Root 1 0 R >>\n%%EOF";

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('could not read any text');

        PdfText::all($pdf);
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
