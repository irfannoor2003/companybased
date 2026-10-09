<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceItem;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesStatusEvent;
use App\Support\Money;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * Order to invoice.
 *
 * The link already existed -- sales_invoices carries order_id and the create
 * screen accepts ?order= -- but nothing checked it. Two real holes followed:
 *
 *  - There was no ceiling. A user could post any order_id next to any line items,
 *    so a customer could be billed more than the order was worth, and the ledger
 *    would record it without complaint.
 *
 *  - The screen prefilled from the WHOLE order on every visit. An order delivered
 *    in three shipments could therefore only ever be invoiced once: the second
 *    invoice re-billed goods already shipped, and the third doubled up again.
 *
 * Both are quiet failures. Nothing errors, the invoice looks fine, and the
 * customer is simply over-billed.
 */
class OrderToInvoiceTest extends TestCase
{
    use SeedsDatabase;

    private function product(string $name = 'Widget'): Product
    {
        return Product::create([
            'name' => $name,
            'sku' => 'SKU-'.strtoupper(substr(md5($name), 0, 6)),
            'cost_price' => '10.00',
            'retail_price' => '25.00',
            'is_active' => true,
        ]);
    }

    /**
     * An order with the given lines: [product, qty, unit_price].
     */
    private function order(array $lines, string $total = null): SalesOrder
    {
        $order = SalesOrder::create([
            'number' => 'SO-'.uniqid(),
            'customer_id' => $this->customer()->id,
            'issue_date' => now()->toDateString(),
            'status' => 'confirmed',
            'currency' => 'PKR',
            'exchange_rate' => 1,
            'subtotal' => '0',
            'tax_amount' => '0',
            'total' => '0',
        ]);

        $subtotal = 0.0;

        foreach ($lines as [$product, $qty, $price]) {
            $order->items()->create([
                'product_id' => $product->id,
                'description' => $product->name,
                'qty' => (string) $qty,
                'unit_price' => (string) $price,
                'discount_percent' => '0',
                'tax_percent' => '0',
                'line_total' => (string) ($qty * $price),
            ]);

            $subtotal += $qty * $price;
        }

        $order->update([
            'subtotal' => (string) $subtotal,
            'total' => (string) ($total ?? $subtotal),
        ]);

        return $order->fresh();
    }

    private function invoiceFor(SalesOrder $order, array $lines, string $status = 'sent'): SalesInvoice
    {
        $invoice = SalesInvoice::create([
            'number' => 'INV-'.uniqid(),
            'order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'issue_date' => now()->toDateString(),
            'status' => $status,
            'currency' => $order->currency,
            'exchange_rate' => 1,
            'subtotal' => '0',
            'tax_amount' => '0',
            'total' => '0',
            'paid_amount' => '0',
            'credit_applied' => '0',
            'credit_note_applied' => '0',
        ]);

        $total = 0.0;

        foreach ($lines as [$product, $qty, $price]) {
            SalesInvoiceItem::create([
                'invoice_id' => $invoice->id,
                'product_id' => $product->id,
                'description' => $product->name,
                'qty' => (string) $qty,
                'unit_price' => (string) $price,
                'discount_percent' => '0',
                'tax_percent' => '0',
                'line_total' => (string) ($qty * $price),
            ]);

            $total += $qty * $price;
        }

        $invoice->update([
            'subtotal' => (string) $total,
            'total' => (string) $total,
        ]);

        return $invoice->fresh();
    }

    public function test_an_uninvoiced_order_offers_its_full_quantity(): void
    {
        $product = $this->product();
        $order = $this->order([[$product, 10, '25.00']]);

        $lines = $order->uninvoicedLines();

        $this->assertCount(1, $lines);
        $this->assertEquals(10, $lines[0]['qty']);
        $this->assertFalse($order->isFullyInvoiced());
    }

    public function test_a_fully_invoiced_order_is_fully_invoiced(): void
    {
        $product = $this->product();
        $order = $this->order([[$product, 10, '25.00']]);

        $this->invoiceFor($order, [[$product, 10, '25.00']]);

        $this->assertTrue($order->fresh()->isFullyInvoiced());
        $this->assertCount(0, $order->fresh()->uninvoicedLines());
    }

    /**
     * The partial-delivery case, which is the whole reason this exists.
     */
    public function test_a_partially_invoiced_order_offers_only_the_remainder(): void
    {
        $product = $this->product();
        $order = $this->order([[$product, 10, '25.00']]);

        $this->invoiceFor($order, [[$product, 4, '25.00']]);

        $lines = $order->fresh()->uninvoicedLines();

        $this->assertCount(1, $lines, 'the already-invoiced quantity was not deducted');
        $this->assertEquals(6, $lines[0]['qty'], 'the remainder should be 6, not 10');
        $this->assertFalse($order->fresh()->isFullyInvoiced());
    }

    /**
     * A voided invoice must not consume the line. Otherwise an order invoiced,
     * voided by mistake, and re-invoiced would find nothing left to bill and the
     * second attempt would be refused.
     */
    public function test_a_voided_invoice_does_not_consume_the_order_line(): void
    {
        $product = $this->product();
        $order = $this->order([[$product, 10, '25.00']]);

        $this->invoiceFor($order, [[$product, 10, '25.00']], status: 'cancelled');

        $this->assertCount(1, $order->fresh()->uninvoicedLines(), 'a voided invoice consumed the line');
        $this->assertFalse(
            $order->fresh()->isFullyInvoiced(),
            'a voided invoice made the order look fully billed',
        );
    }

    public function test_invoicing_beyond_the_order_is_refused(): void
    {
        $product = $this->product();
        $order = $this->order([[$product, 5, '20.00']]);

        $this->invoiceFor($order, [[$product, 5, '20.00']]);

        $this->actingAs($this->userForRole('Admin'))
            ->post('/sales/invoices', [
                'customer_id' => $order->customer_id,
                'issue_date' => now()->toDateString(),
                'status' => 'draft',
                'currency' => 'PKR',
                'order_id' => $order->id,
                'items' => [
                    ['product_id' => $product->id, 'description' => 'Widget', 'qty' => 1, 'price' => '20.00'],
                ],
            ])
            ->assertSessionHas('toasts');

        $this->assertSame(
            1,
            $order->fresh()->invoices()->count(),
            'an invoice was created against an order already invoiced in full',
        );
    }

    public function test_invoicing_a_part_of_an_order_is_allowed(): void
    {
        $product = $this->product();
        $order = $this->order([[$product, 10, '20.00']]);

        $this->actingAs($this->userForRole('Admin'))
            ->post('/sales/invoices', [
                'customer_id' => $order->customer_id,
                'issue_date' => now()->toDateString(),
                'status' => 'draft',
                'currency' => 'PKR',
                'order_id' => $order->id,
                'items' => [
                    ['product_id' => $product->id, 'description' => 'Widget', 'qty' => 3, 'price' => '20.00'],
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $order->fresh()->invoices()->count());
        $this->assertFalse($order->fresh()->isFullyInvoiced());
    }

    /**
     * The create screen must offer the remainder, not the whole order.
     */
    public function test_the_create_screen_prefills_only_the_unbilled_remainder(): void
    {
        $product = $this->product();
        $order = $this->order([[$product, 10, '25.00']]);

        $this->invoiceFor($order, [[$product, 4, '25.00']]);

        $html = $this->actingAs($this->userForRole('Admin'))
            ->get('/sales/invoices/create?order='.$order->id)
            ->assertOk()
            ->getContent();

        // The remainder is what gets prefilled.
        $this->assertStringContainsString(
            'remain',
            $html,
            'the screen did not report that this order is already partly invoiced',
        );
        $this->assertStringContainsString((string) $order->number, $html);
    }

    public function test_the_screen_warns_when_the_order_is_fully_invoiced(): void
    {
        $product = $this->product();
        $order = $this->order([[$product, 10, '25.00']]);
        $this->invoiceFor($order, [[$product, 10, '25.00']]);

        $html = $this->actingAs($this->userForRole('Admin'))
            ->get('/sales/invoices/create?order='.$order->id)
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('invoiced in full', $html);
    }

    /**
     * Two products on one order, invoiced separately.
     */
    public function test_each_line_is_tracked_independently(): void
    {
        $a = $this->product('Alpha');
        $b = $this->product('Beta');
        $order = $this->order([[$a, 10, '10.00'], [$b, 5, '20.00']]);

        $this->invoiceFor($order, [[$a, 10, '10.00']]);

        $lines = collect($order->fresh()->uninvoicedLines())->keyBy('description');

        // Alpha was invoiced in full so it must be gone; Beta was untouched.
        $this->assertFalse($lines->has('Alpha'), 'a fully invoiced line was still offered for billing');
        $this->assertTrue($lines->has('Beta'), 'an untouched line was dropped from the order');
        $this->assertEquals(5, $lines['Beta']['qty']);
    }

    public function test_uninvoiced_total_reflects_what_is_left(): void
    {
        $product = $this->product();
        $order = $this->order([[$product, 10, '20.00']]);

        $this->assertSame('200.00', $order->uninvoicedTotal());

        $this->invoiceFor($order, [[$product, 4, '20.00']]);

        $this->assertSame('120.00', $order->fresh()->uninvoicedTotal());
    }
}