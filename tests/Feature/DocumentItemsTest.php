<?php

namespace Tests\Feature;

use App\Models\SalesInvoice;
use App\Support\DocumentItems;
use Illuminate\Validation\ValidationException;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * DocumentItems::sync() is called by roughly 44 controllers across sales,
 * purchasing, inventory and accounting. It is the one place that turns raw
 * request line items into stored line items and header totals, so its arithmetic
 * and its atomicity matter everywhere.
 *
 * The invariants:
 *  - the returned subtotal equals the sum of the stored line nets
 *  - total = subtotal + tax, always
 *  - the header columns are updated from the same figures the lines are stored at
 *  - a rejected line leaves the previous lines intact (delete-then-insert is atomic)
 */
class DocumentItemsTest extends TestCase
{
    use SeedsDatabase;

    private function invoice(): SalesInvoice
    {
        return SalesInvoice::create([
            'number' => 'INV-'.uniqid(),
            'customer_id' => $this->customer()->id,
            'issue_date' => now()->toDateString(),
            'status' => 'draft',
            'currency' => 'PKR',
            'exchange_rate' => 1,
            'subtotal' => '0',
            'tax_amount' => '0',
            'total' => '0',
            'paid_amount' => '0',
        ]);
    }

    private function line(string $desc, string $qty, string $price, string $discount = '0', string $tax = '0'): array
    {
        return [
            'description' => $desc,
            'qty' => $qty,
            'unit_price' => $price,
            'discount_percent' => $discount,
            'tax_percent' => $tax,
        ];
    }

    public function test_it_stores_lines_and_returns_matching_totals(): void
    {
        $invoice = $this->invoice();

        $totals = DocumentItems::sync($invoice, [
            $this->line('Widget', '2', '50.00'),
            $this->line('Gadget', '1', '30.00'),
        ]);

        $this->assertCount(2, $invoice->fresh()->items);
        $this->assertEqualsWithDelta(130.0, $totals['subtotal'], 0.0005);
        $this->assertEqualsWithDelta(0.0, $totals['tax'], 0.0005);
        $this->assertEqualsWithDelta(130.0, $totals['total'], 0.0005);
    }

    public function test_total_is_always_subtotal_plus_tax(): void
    {
        $invoice = $this->invoice();

        $totals = DocumentItems::sync($invoice, [
            $this->line('Taxed', '1', '100.00', '0', '16.67'),
        ]);

        $this->assertEqualsWithDelta(
            $totals['subtotal'] + $totals['tax'],
            $totals['total'],
            0.0005,
            'total must equal subtotal plus tax',
        );
    }

    public function test_a_line_discount_reduces_the_net_but_not_tax_on_the_gross(): void
    {
        $invoice = $this->invoice();

        // 100.00 less 10% = 90.00 net, then 10% tax on the net = 9.00.
        $totals = DocumentItems::sync($invoice, [
            $this->line('Discounted', '1', '100.00', '10', '10'),
        ]);

        $this->assertEqualsWithDelta(90.0, $totals['subtotal'], 0.0005);
        $this->assertEqualsWithDelta(9.0, $totals['tax'], 0.0005);
        $this->assertEqualsWithDelta(99.0, $totals['total'], 0.0005);
    }

    public function test_the_subtotal_equals_the_sum_of_the_stored_line_nets(): void
    {
        $invoice = $this->invoice();

        $totals = DocumentItems::sync($invoice, [
            $this->line('A', '3', '10.00', '5'),
            $this->line('B', '1', '7.35', '0'),
            $this->line('C', '2', '1.11', '0'),
        ]);

        $lineSum = (float) $invoice->fresh()->items->sum('line_total');

        $this->assertEqualsWithDelta($lineSum, $totals['subtotal'], 0.005, 'the header must match the printed lines');
    }

    public function test_syncing_twice_replaces_rather_than_appends(): void
    {
        $invoice = $this->invoice();

        DocumentItems::sync($invoice, [$this->line('First', '1', '10.00')]);
        $totals = DocumentItems::sync($invoice, [$this->line('Second', '2', '20.00')]);

        $this->assertCount(1, $invoice->fresh()->items, 'lines must be replaced');
        $this->assertEqualsWithDelta(40.0, $totals['subtotal'], 0.0005);
    }

    public function test_a_zero_discount_is_accepted(): void
    {
        $invoice = $this->invoice();

        $totals = DocumentItems::sync($invoice, [$this->line('No discount', '1', '100.00', '0')]);

        $this->assertEqualsWithDelta(100.0, $totals['subtotal'], 0.0005);
    }

    public function test_a_free_item_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        DocumentItems::sync($this->invoice(), [$this->line('Free', '0', '100.00')]);
    }

    public function test_a_negative_price_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        DocumentItems::sync($this->invoice(), [$this->line('Negative', '1', '-50.00')]);
    }

    public function test_a_discount_above_one_hundred_percent_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        DocumentItems::sync($this->invoice(), [$this->line('Over-discounted', '1', '100.00', '150')]);
    }

    public function test_a_tax_above_one_hundred_percent_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        DocumentItems::sync($this->invoice(), [$this->line('Over-taxed', '1', '100.00', '0', '120')]);
    }

    public function test_a_line_without_a_description_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        DocumentItems::sync($this->invoice(), [$this->line('   ', '1', '100.00')]);
    }

    public function test_a_non_numeric_quantity_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        DocumentItems::sync($this->invoice(), [$this->line('Bad qty', 'abc', '100.00')]);
    }

    /**
     * delete-then-insert is two statements. Without the transaction around them
     * a rejected line would leave the document with zero items but its previous
     * header totals, which is why sync() wraps both in DB::transaction.
     */
    public function test_a_rejected_line_leaves_the_previous_lines_and_totals_intact(): void
    {
        $invoice = $this->invoice();

        $original = DocumentItems::sync($invoice, [
            $this->line('Keep me', '2', '25.00'),
        ]);
        $invoice->update([
            'subtotal' => $original['subtotal'],
            'tax_amount' => $original['tax'],
            'total' => $original['total'],
        ]);

        try {
            DocumentItems::sync($invoice, [
                $this->line('Valid', '1', '10.00'),
                $this->line('Invalid', '-5', '10.00'),
            ]);
            $this->fail('expected the invalid line to be rejected');
        } catch (ValidationException) {
            // expected
        }

        $refreshed = $invoice->fresh();

        $this->assertCount(1, $refreshed->items, 'the previous line must survive');
        $this->assertSame('Keep me', $refreshed->items->first()->description);
        $this->assertEqualsWithDelta(50.0, (float) $refreshed->subtotal, 0.0005);
        $this->assertEqualsWithDelta(50.0, (float) $refreshed->total, 0.0005);
    }
}
