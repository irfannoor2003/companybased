<?php

namespace Tests\Feature;

use App\Models\InventoryIncomingShipment;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Services\Inventory\ValuationService;
use App\Support\InventoryLedger;
use App\Support\Money;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * Inventory is valued at what the business actually paid.
 *
 * Cost of goods sold was recognised at the product's `cost_price` -- a figure
 * someone typed onto the product record. That is only right if every purchase of
 * that product happened at the same price. A business that buys the same item at
 * 100 and then at 140 is carrying stock worth neither, and the margin it reports on
 * the sale in between is a fiction: understated while prices rise, overstated while
 * they fall.
 *
 * The error is invisible rather than obvious. Because the same invented figure
 * relieves Inventory as it debits cost of goods sold, the two statements still agree
 * with each other and nothing looks wrong. Only reading the purchase invoices
 * reveals it.
 *
 * These tests pin the method's defining properties, which is what makes the number
 * worth anything:
 *
 *   - a receipt blends into the average, weighted by what was already on the shelf
 *   - a sale does NOT move the average (weighted average consumes no cost layers)
 *   - what was paid equals what is relieved plus what remains, exactly
 */
class WeightedAverageValuationTest extends TestCase
{
    use SeedsDatabase;

    private function product(string $costPrice = '100.00'): Product
    {
        return Product::create([
            'sku' => 'SKU-'.strtoupper(substr(md5($costPrice.microtime()), 0, 8)),
            'name' => 'Widget',
            'price' => 200.00,
            'cost_price' => $costPrice,
            'is_active' => true,
            'track_inventory' => true,
        ]);
    }

    private function item(Product $product): InventoryItem
    {
        return InventoryItem::create(['product_id' => $product->id, 'is_active' => true]);
    }

    /**
     * Receive stock at a price, which is what moves the average.
     */
    private function receive(InventoryItem $item, string $qty, string $unitCost): void
    {
        InventoryLedger::adjust(
            $item->id,
            $this->warehouse()->id,
            $qty,
            'incoming_shipment',
            null,
            'test receipt',
            $unitCost,
        );
    }

    private function average(InventoryItem $item): float
    {
        return (float) InventoryItem::find($item->id)->moving_average_cost;
    }

    /**
     * The first receipt simply becomes the average. There is nothing to blend with,
     * and averaging against a stale figure at zero quantity would dilute the real
     * price towards whatever the last one happened to be.
     */
    public function test_the_first_receipt_sets_the_average(): void
    {
        $item = $this->item($this->product('100.00'));

        $this->receive($item, '10', '100.00');

        $this->assertEqualsWithDelta(100.0, $this->average($item), 0.0001);
    }

    /**
     * The core case. (10 x 100 + 10 x 140) / 20 = 120.
     *
     * Under the old `cost_price` behaviour this invoice would have been costed at
     * whatever was typed on the product -- 100 or 140, neither of which is what the
     * stock was worth.
     */
    public function test_a_second_receipt_blends_into_the_average_by_quantity(): void
    {
        $item = $this->item($this->product('100.00'));

        $this->receive($item, '10', '100.00');
        $this->receive($item, '10', '140.00');

        $this->assertEqualsWithDelta(120.0, $this->average($item), 0.0001, '(10x100 + 10x140) / 20');
    }

    /**
     * The blend is weighted by QUANTITY, not by money and not by receipt count.
     *
     * Two receipts of equal VALUE (1,000 each) at very different volumes give an
     * average nowhere near the mean of the two prices. Weighted average weights by
     * units, so the larger volume dominates and the result sits close to the cheap
     * price: (100 x 10 + 10 x 100) / 110 = 18.1818.
     *
     * A method that averaged the prices instead (55.00), or averaged the receipts,
     * would value the stock at something the business never paid for it.
     */
    public function test_the_blend_is_weighted_by_quantity_not_by_money(): void
    {
        $item = $this->item($this->product());

        $this->receive($item, '100', '10.00');   // 1,000
        $this->receive($item, '10', '100.00');   // 1,000

        $this->assertEqualsWithDelta(
            (100 * 10.00 + 10 * 100.00) / 110,
            $this->average($item),
            0.0001,
            'the average must be weighted by units on hand',
        );

        // Explicitly not the mean of the two prices.
        $this->assertNotEqualsWithDelta(55.0, $this->average($item), 0.5);
    }

    /**
     * Weighted average consumes no cost layers, so a sale must leave the average
     * exactly where it was. This is what distinguishes the method from FIFO, and it
     * is why there is no layer table that can drift out of step with the stock rows.
     */
    public function test_a_sale_does_not_move_the_average(): void
    {
        $item = $this->item($this->product());

        $this->receive($item, '10', '100.00');
        $this->receive($item, '10', '140.00');
        $before = $this->average($item);

        InventoryLedger::adjust($item->id, $this->warehouse()->id, '-5', 'pos_sale', null, 'test sale');

        $this->assertEqualsWithDelta($before, $this->average($item), 0.0001, 'a sale must not change the average');
        $this->assertEqualsWithDelta(15.0, (float) InventoryLedger::onHand($item->id), 0.001);
    }

    /**
     * Value must be conserved exactly: what was paid equals what was relieved plus
     * what is left. Any drift here is a real accounting loss.
     */
    public function test_value_is_conserved_across_receipts_and_a_sale(): void
    {
        $item = $this->item($this->product());

        $this->receive($item, '10', '100.00');  // 1,000
        $this->receive($item, '10', '140.00');  // 1,400
        $totalPaid = 2400.00;

        $valuation = app(ValuationService::class);
        $average = $this->average($item);

        InventoryLedger::adjust($item->id, $this->warehouse()->id, '-5', 'pos_sale', null, 'test sale');

        $relieved = $average * 5;
        $closing = (float) $valuation->stockValue($item->id);

        $this->assertEqualsWithDelta($totalPaid, $relieved + $closing, 0.01, 'value was lost or created');
        $this->assertEqualsWithDelta(15.0, (float) InventoryLedger::onHand($item->id), 0.001);
    }

    /**
     * A receipt with no cost must not move the average.
     *
     * Blending a zero-cost receipt in would write off the value of everything
     * already on the shelf -- a goods receipt whose cost has not been entered yet
     * would silently destroy the value of the stock around it.
     */
    public function test_a_receipt_with_no_cost_leaves_the_average_alone(): void
    {
        $item = $this->item($this->product());

        $this->receive($item, '10', '100.00');
        InventoryLedger::adjust($item->id, $this->warehouse()->id, '5', 'incoming_shipment', null, 'no cost');

        $this->assertEqualsWithDelta(100.0, $this->average($item), 0.0001, 'a costless receipt must not dilute the average');
        $this->assertEqualsWithDelta(15.0, (float) InventoryLedger::onHand($item->id), 0.001, 'the quantity still moved');
    }

    /**
     * Running the shelf to zero and buying again resets the average rather than
     * blending against a stock level that no longer exists.
     */
    public function test_rebuying_after_the_shelf_is_empty_resets_the_average(): void
    {
        $item = $this->item($this->product());

        $this->receive($item, '10', '100.00');
        InventoryLedger::adjust($item->id, $this->warehouse()->id, '-10', 'pos_sale', null, 'sold out');

        $this->receive($item, '5', '250.00');

        $this->assertEqualsWithDelta(250.0, $this->average($item), 0.0001);
    }

    /**
     * An item that has never been received keeps valuing at the hand-set cost
     * price, so a catalogue set up before this existed is not suddenly worth zero.
     */
    public function test_an_item_with_no_average_falls_back_to_the_product_cost_price(): void
    {
        $item = $this->item($this->product('42.50'));
        $item->forceFill(['moving_average_cost' => null])->save();

        $this->assertEqualsWithDelta(
            42.50,
            (float) app(ValuationService::class)->averageCost($item->fresh()),
            0.0001,
        );
    }

    /**
     * A service line has no inventory item and therefore no cost of goods, which is
     * the correct answer rather than a missing one.
     */
    public function test_a_product_with_no_inventory_item_has_no_cost(): void
    {
        $this->assertNull(app(ValuationService::class)->costForProduct(null));
        $this->assertNull(app(ValuationService::class)->costForProduct(999999));
    }

    /**
     * The average is held to four decimals. A unit cost of 0.0025 is real in
     * packaging and chemicals, and `round()` exists because the money scale would
     * flatten it.
     */
    public function test_unit_costs_keep_four_decimals(): void
    {
        $this->assertSame('0.0025', Money::round('0.00245', 4));
        $this->assertSame('0.0024', Money::round('0.002449', 4));
        $this->assertSame('100.00', Money::round('99.99999', 2));
        $this->assertSame('-12.3457', Money::round('-12.3456789', 4));
    }

    /**
     * An incoming shipment carries the price the goods were invoiced at, so
     * approving one has to feed the average. Otherwise the only path that moves the
     * average is a purchase order, and stock arriving by shipment would be valued
     * at whatever the last purchase happened to cost.
     */
    public function test_an_incoming_shipment_feeds_the_average(): void
    {
        $product = $this->product('100.00');
        $item = $this->item($product);

        $this->receive($item, '10', '100.00');

        $shipment = InventoryIncomingShipment::create([
            'number' => 'GRN-1',
            'supplier_id' => null,
            'warehouse_id' => $this->warehouse()->id,
            'status' => 'draft',
            'expected_date' => now()->toDateString(),
        ]);

        $shipment->items()->create([
            'inventory_item_id' => $item->id,
            'product_id' => $product->id,
            'item_name' => 'Widget',
            'expected_quantity' => 10,
            'received_quantity' => 10,
            'unit_cost' => '200.00',
        ]);

        InventoryLedger::applyIncomingShipment($shipment);

        $this->assertEqualsWithDelta(150.0, $this->average($item), 0.0001, '(10x100 + 10x200) / 20');
    }
}
