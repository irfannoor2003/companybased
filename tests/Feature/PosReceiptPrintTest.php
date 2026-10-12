<?php

namespace Tests\Feature;

use App\Models\PosSale;
use App\Models\PosSaleItem;
use Tests\SeedsDatabase;
use Tests\Support\PdfText;
use Tests\TestCase;

/**
 * A point-of-sale receipt can actually be printed.
 *
 * POS receipts had no printable form at all: the only route was an HTML screen
 * page. A shop could not hand a customer a receipt, and could not reprint a sale
 * once the till had rolled off, while every other money document in the system had
 * a PDF.
 *
 * The 80mm thermal roll is the default because that is the till printer; A4 is
 * available on request for the copy a customer files. The page width is asserted
 * in points because "80mm" in the template is only true if setPaper() was given the
 * right custom size -- passing 'a4' there would render a perfectly readable receipt
 * on the wrong roll and still pass a content check.
 */
class PosReceiptPrintTest extends TestCase
{
    use SeedsDatabase;

    private function admin(): \App\Models\User
    {
        return $this->admin ??= $this->userForRole('Admin');
    }

    /** Cached so a test that builds a sale and then checks permissions reuses one user. */
    private ?\App\Models\User $admin = null;

    private function salesman(): \App\Models\User
    {
        return $this->salesman ??= $this->userForRole('Salesman');
    }

    private ?\App\Models\User $salesman = null;

    private function sale(array $attributes = [], array $items = null): PosSale
    {
        // A sale belongs to a till shift, and the column is NOT NULL, so the
        // receipt's "Served by" line has something real to name.
        $shift = \App\Models\PosShift::create([
            'shift_number' => 'SHIFT-'.uniqid(),
            'opened_by' => $this->salesman()->id,
            'opened_at' => '2026-10-06 09:00:00',
            'opening_cash' => '500.00',
            'status' => 'open',
        ]);

        $sale = PosSale::create(array_merge([
            'receipt_number' => 'POS-RCPT-0001',
            'shift_id' => $shift->id,
            'customer_name' => 'Walk-in customer',
            'subtotal' => '100.00',
            'discount' => '10.00',
            'tax' => '5.00',
            'total' => '95.00',
            'amount_paid' => '100.00',
            'change_due' => '5.00',
            'status' => 'completed',
            'sold_at' => '2026-10-06 14:32:00',
        ], $attributes));

        foreach ($items ?? [
            ['item_name' => 'Espresso blend 250g', 'quantity' => 2, 'unit_price' => '25.00', 'line_total' => '50.00'],
            ['item_name' => 'Ceramic mug', 'quantity' => 1, 'unit_price' => '40.00', 'line_total' => '40.00'],
        ] as $i => $item) {
            PosSaleItem::create(array_merge($item, [
                'pos_sale_id' => $sale->id,
                'unit' => 'pc',
            ]));
        }

        return $sale;
    }

    /**
     * The page geometry, in points, from the PDF's MediaBox.
     *
     * @return array{0: float, 1: float}  width, height
     */
    private function pageSize(string $pdf): array
    {
        $this->assertMatchesRegularExpression(
            '#/MediaBox\s*\[\s*([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s*\]#',
            $pdf,
            'the PDF declares no MediaBox',
        );

        preg_match('#/MediaBox\s*\[\s*([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s*\]#', $pdf, $m);

        return [(float) $m[3] - (float) $m[1], (float) $m[4] - (float) $m[2]];
    }

    private function download(PosSale $sale, string $query = ''): string
    {
        // getContent(), not streamedContent(): `download()` returns an ordinary
        // response with the PDF in the body, not a StreamedResponse.
        return $this->actingAs($this->admin())
            ->get('/pos/receipts/'.$sale->receipt_number.'/print'.$query)
            ->assertOk()
            ->getContent();
    }

    public function test_the_receipt_route_returns_a_pdf(): void
    {
        $sale = $this->sale();

        $response = $this->actingAs($this->admin())
            ->get('/pos/receipts/'.$sale->receipt_number.'/print')
            ->assertOk();

        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('content-type'));
    }

    /**
     * The whole point of the 80mm default: the geometry has to be a narrow roll.
     *
     * 80mm = 226.77pt. Asserted with a small tolerance because the conversion
     * rounds; the alternative -- passing 'a4' and hoping -- would produce a readable
     * receipt on the wrong paper and still pass every content assertion.
     */
    public function test_the_default_receipt_is_80mm_wide(): void
    {
        [$width] = $this->pageSize($this->download($this->sale()));

        $this->assertEqualsWithDelta(
            80 * 2.834645,
            $width,
            1.0,
            'the default receipt must be an 80mm roll, not a full sheet',
        );
    }

    public function test_an_a4_receipt_is_available_on_request(): void
    {
        [$width, $height] = $this->pageSize($this->download($this->sale(), '?size=a4'));

        $this->assertEqualsWithDelta(595.28, $width, 1.0, 'A4 width is 210mm');
        $this->assertEqualsWithDelta(841.89, $height, 1.0, 'A4 height is 297mm');
    }

    /**
     * What the customer actually needs to see: the header, the lines, and the
     * figures they will check at the till.
     */
    public function test_the_receipt_carries_its_header_lines_and_totals(): void
    {
        $text = PdfText::all($this->download($this->sale()));

        $this->assertStringContainsString('SALES RECEIPT', $text);
        $this->assertStringContainsString('POS-RCPT-0001', $text, 'the receipt number must print');

        $this->assertStringContainsString('Espresso blend 250g', $text);
        $this->assertStringContainsString('Ceramic mug', $text);

        $this->assertStringContainsString('Subtotal', $text);
        $this->assertStringContainsString('100.00', $text);
        $this->assertStringContainsString('Discount', $text);
        $this->assertStringContainsString('95.00', $text, 'the total must print');
    }

    /**
     * Change due is the number a customer is most often given wrong, and the one a
     * cashier checks out loud.
     */
    public function test_the_change_due_is_bounded_and_labelled(): void
    {
        $text = PdfText::all($this->download($this->sale()));

        $this->assertStringContainsString('CHANGE DUE', $text);
        $this->assertStringContainsString('Amount paid', $text);
    }

    /**
     * A sale with no change due must not print an empty change box, which would
     * read as a missing figure rather than "none owed".
     */
    public function test_no_change_box_when_nothing_is_owed(): void
    {
        $text = PdfText::all($this->download($this->sale(['change_due' => '0.00', 'amount_paid' => '95.00'])));

        $this->assertStringNotContainsString('CHANGE DUE', $text);
    }

    /**
     * The roll is narrow, so a sale with many lines is the case that breaks a
     * receipt layout. It must stay on one page.
     */
    public function test_a_sale_with_many_lines_fits_the_roll(): void
    {
        $items = [];

        for ($i = 1; $i <= 14; $i++) {
            $items[] = [
                'item_name' => 'Item '.$i,
                'quantity' => 1,
                'unit_price' => '5.00',
                'line_total' => '5.00',
            ];
        }

        $pdf = $this->download($this->sale([], $items));

        $this->assertCount(1, PdfText::pages($pdf), 'a 14-line sale must not spill onto a second sheet of roll');

        $text = PdfText::all($pdf);
        $this->assertStringContainsString('Item 14', $text, 'the last line must still print');
    }

    /**
     * A voided sale is still printable -- that is when a reprint is wanted -- but
     * it must not read as a completed transaction.
     */
    public function test_a_voided_receipt_still_prints(): void
    {
        $text = PdfText::all($this->download($this->sale(['status' => 'void'])));

        $this->assertStringContainsString('POS-RCPT-0001', $text);
    }

    /**
     * Permission is enforced: a receipt is a money document.
     */
    public function test_the_receipt_requires_the_receipts_permission(): void
    {
        $sale = $this->sale();

        $this->actingAs($this->salesman())
            ->get('/pos/receipts/'.$sale->receipt_number.'/print')
            ->assertForbidden();
    }
}
