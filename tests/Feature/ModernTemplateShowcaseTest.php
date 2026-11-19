<?php

namespace Tests\Feature;

use App\Models\DocumentTemplate;
use App\Models\SalesInvoice;
use App\Support\DocumentData;
use App\Support\PdfLayout;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * Two showcase designs ship with the seeder, one per editing mechanism:
 *
 *   - Modern Flow: no custom code, only a block order an operator could have
 *     produced by dragging the rows. Proves drag-reordering is a real design.
 *   - Modern Signature: a normal block order plus custom header HTML, footer
 *     HTML and a stylesheet. Proves custom code is a different, real design.
 *
 * Both print through the same frame; neither is the default.
 */
class ModernTemplateShowcaseTest extends TestCase
{
    use SeedsDatabase;

    private function flow(): DocumentTemplate
    {
        return DocumentTemplate::where('slug', 'modern-flow-invoice')->firstOrFail();
    }

    private function signature(): DocumentTemplate
    {
        return DocumentTemplate::where('slug', 'modern-signature-invoice')->firstOrFail();
    }

    private function htmlFor(DocumentTemplate $template): string
    {
        $invoice = SalesInvoice::create([
            'number' => 'INV-MODERN-'.uniqid(),
            'customer_id' => $this->customer('Modern Co')->id,
            'issue_date' => now()->toDateString(),
            'status' => 'sent',
            'currency' => 'PKR',
            'exchange_rate' => 1,
            'subtotal' => '1000.00',
            'tax_amount' => '0.00',
            'total' => '1000.00',
            'notes' => 'Delivered and accepted by the customer.',
        ]);

        $invoice->load(['customer', 'items.product']);

        $data = DocumentData::build($invoice);
        $data['layout'] = PdfLayout::forTemplate($template);

        return view('documents.pdf', $data)->render();
    }

    public function test_both_modern_showcase_templates_are_seeded_as_non_defaults(): void
    {
        foreach ([$this->flow(), $this->signature()] as $template) {
            $this->assertSame('invoice', $template->type);
            $this->assertSame(PdfLayout::STYLE_MODERN, $template->style);
            $this->assertFalse($template->is_default, 'a showcase must not replace the default design');
        }

        $this->assertNotSame($this->flow()->name, $this->signature()->name);
    }

    public function test_modern_flow_is_a_drag_reordered_block_sequence_with_no_custom_code(): void
    {
        $template = $this->flow();

        $this->assertNull($template->header_html);
        $this->assertNull($template->footer_html);
        $this->assertNull($template->css);

        // A deliberately different order from the default, reachable by dragging.
        $this->assertSame(
            ['header', 'title', 'party', 'meta', 'items', 'totals', 'notes', 'signature', 'bank', 'footer'],
            $template->layout['blocks'],
        );
        $this->assertNotSame(PdfLayout::DEFAULT_BLOCK_ORDER, $template->layout['blocks']);

        $html = $this->htmlFor($template);
        $this->assertStringContainsString('style-modern', $html);
        $this->assertStringNotContainsString('modern-ribbon', $html, 'the drag design carries no custom code');
    }

    public function test_modern_flow_puts_notes_before_bank_in_the_printed_canvas(): void
    {
        // The bank block only prints when the company has an account number;
        // without one both blocks would be skipped and the order untestable.
        \App\Models\Setting::set('company.bank_account_number', 'PK36-TEST-0001');
        \App\Models\Setting::flushCache();

        $html = $this->htmlFor($this->flow());

        $notesAt = strpos($html, 'class="notes"');
        $bankAt = strpos($html, 'rail-label">Bank');

        $this->assertNotFalse($notesAt, 'the notes block did not print');
        $this->assertNotFalse($bankAt, 'the bank block did not print');
        $this->assertLessThan($bankAt, $notesAt, 'drag-reordered notes must print before bank details');
    }

    public function test_modern_signature_renders_its_custom_code(): void
    {
        $template = $this->signature();

        // The custom code is stored on the template itself.
        $this->assertStringContainsString('CERTIFIED LOCAL PARTNER', (string) $template->header_html);
        $this->assertStringContainsString('Queries within 14 days', (string) $template->footer_html);
        $this->assertStringContainsString('table.totals tr.grand td', (string) $template->css);

        $html = $this->htmlFor($template);

        // Both HTML hooks and the stylesheet actually reach the printed page.
        $this->assertStringContainsString('CERTIFIED LOCAL PARTNER', $html);
        $this->assertStringContainsString('Queries within 14 days', $html);
        $this->assertStringContainsString('modern-ribbon', $html);
        // The showcase restyles typography and spacing but deliberately keeps no
        // hardcoded palette: every preset now follows the superadmin brand colour.
        $this->assertStringContainsString('border-bottom-width: 3px', $html);
        $this->assertStringContainsString('custom-html-header', $html);
        $this->assertStringContainsString('custom-html-footer', $html);
    }

    public function test_the_two_designs_are_genuinely_different(): void
    {
        $flowHtml = $this->htmlFor($this->flow());
        $signatureHtml = $this->htmlFor($this->signature());

        $this->assertStringNotContainsString('CERTIFIED LOCAL PARTNER', $flowHtml);
        $this->assertStringContainsString('CERTIFIED LOCAL PARTNER', $signatureHtml);

        // The custom-code design injects its palette; the drag design uses the
        // shared invoice palette, so the two PDFs cannot be byte-identical.
        $this->assertNotSame($flowHtml, $signatureHtml);
    }
}
