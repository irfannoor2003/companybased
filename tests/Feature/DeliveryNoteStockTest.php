<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\InventoryWarehouse;
use App\Models\Product;
use App\Models\SalesCustomer;
use App\Models\SalesDeliveryNote;
use App\Support\InventoryLedger;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * A delivery note has to move stock.
 *
 * Creating one used to write a document and nothing else. The goods were recorded
 * as having left the building, and the warehouse carried on showing them as though
 * they were still on the shelf. The only place stock was ever relieved was the
 * cost-of-goods leg when the invoice happened to be raised.
 *
 * That is wrong in two directions, not one:
 *
 *  - Deliver-then-bill leaves the warehouse overstating stock for as long as the
 *    invoice is outstanding, so buying decisions are made on goods that are gone.
 *  - A delivery that is invoiced but then lost, returned or never dispatched has
 *    already relieved stock through COGS with nothing to show the goods left.
 *
 * Stock is relieved at the `shipped` transition, because `pending` and `packed`
 * are still on the shelf.
 */
class DeliveryNoteStockTest extends TestCase
{
    use SeedsDatabase;

    private function warehouse(): InventoryWarehouse
    {
        // `code` is NOT NULL with no default; it is part of the warehouse record,
        // not an optional annotation.
        return InventoryWarehouse::query()->where('is_active', true)->first()
            ?? InventoryWarehouse::create([
                'name' => 'Main Warehouse',
                'code' => 'MAIN',
                'is_active' => true,
            ]);
    }

    private function product(): Product
    {
        return Product::query()->first() ?? Product::create([
            'name' => 'Delivered Widget',
            'sku' => 'DW-'.uniqid(),
            'is_active' => true,
            'cost_price' => 10,
            'sale_price' => 20,
        ]);
    }

    private function stockItem(Product $product, float $quantity): InventoryItem
    {
        $item = InventoryItem::query()->where('product_id', $product->id)->first()
            ?? InventoryItem::create(['product_id' => $product->id, 'name' => $product->name]);

        InventoryLedger::adjust($item->id, $this->warehouse()->id, $quantity, 'initial', null, 'Opening');

        return $item;
    }

    private function note(Product $product, float $qty): SalesDeliveryNote
    {
        $customer = SalesCustomer::create([
            'company_name' => 'Delivery Co '.uniqid(),
            'currency' => 'PKR',
            'is_active' => true,
            'opening_balance' => 0,
        ]);

        $note = SalesDeliveryNote::create([
            'number' => 'DN-'.uniqid(),
            'customer_id' => $customer->id,
            'issue_date' => now()->toDateString(),
            'status' => 'pending',
        ]);

        $note->items()->create([
            'product_id' => $product->id,
            'description' => $product->name,
            'qty' => (string) $qty,
            'unit_price' => '20.00',
        ]);

        return $note->fresh();
    }

    /**
     * `onHand()` rounds to three decimal places and returns a string, so a whole
     * number comes back as "100" rather than "100.00". Asserted the way the method
     * actually reports, not the way money is written.
     */
    private function onHandFor(int $inventoryItemId): string
    {
        return InventoryLedger::onHand($inventoryItemId, $this->warehouse()->id);
    }

    public function test_shipping_a_delivery_note_relieves_stock(): void
    {
        $product = $this->product();
        $item = $this->stockItem($product, 100);

        $this->assertSame('100', $this->onHandFor($item->id));

        InventoryLedger::applyDelivery($this->note($product, 30));

        $this->assertSame(
            '70',
            $this->onHandFor($item->id),
            'stock was not relieved when the note was dispatched',
        );
    }

    /**
     * The movement has to be recorded, not just applied: a stock figure that
     * changed with no audit trail behind it cannot be reconciled or reversed.
     */
    public function test_the_relief_is_recorded_as_a_delivery_movement(): void
    {
        $product = $this->product();
        $item = $this->stockItem($product, 100);
        $note = $this->note($product, 30);

        InventoryLedger::applyDelivery($note);

        $movement = \App\Models\InventoryMovement::query()
            ->where('reference_type', SalesDeliveryNote::class)
            ->where('reference_id', $note->id)
            ->where('movement_type', 'delivery_out')
            ->firstOrFail();

        $this->assertEqualsWithDelta(-30, (float) $movement->quantity_change, 0.001);

        // The movement column is `note`; `reference_type`/`reference_id` carry the
        // back-reference to the note itself.
        $this->assertSame($note->number, $movement->note);
    }

    /**
     * A service line has no stock. Crediting it would create a nonsense movement
     * against an inventory item that does not exist.
     */
    public function test_a_service_line_does_not_move_stock(): void
    {
        $product = $this->product();
        $item = $this->stockItem($product, 50);

        $note = $this->note($product, 0);
        $note->items()->update(['product_id' => null, 'description' => 'Installation labour']);

        InventoryLedger::applyDelivery($note->fresh());

        $this->assertSame('50', $this->onHandFor($item->id));
    }

    /**
     * A carrier that fails to collect means the goods come back. The reversal is
     * an explicit `delivery_in` rather than deleting the outbound movement, so the
     * outbound-then-return pair stays visible on the audit trail.
     */
    public function test_a_returned_delivery_puts_the_stock_back(): void
    {
        $product = $this->product();
        $item = $this->stockItem($product, 100);
        $note = $this->note($product, 30);

        InventoryLedger::applyDelivery($note);
        $this->assertSame('70', $this->onHandFor($item->id));

        InventoryLedger::applyDelivery($note, returned: true);

        $this->assertSame(
            '100',
            $this->onHandFor($item->id),
            'the returned delivery did not put the stock back',
        );

        $this->assertSame(
            1,
            \App\Models\InventoryMovement::query()
                ->where('reference_id', $note->id)
                ->where('movement_type', 'delivery_in')
                ->count(),
            'no delivery_in movement was recorded for the return',
        );
    }

    /**
     * Stock cannot go negative just because a note says so.
     */
    public function test_a_delivery_larger_than_stock_is_refused(): void
    {
        $product = $this->product();
        $item = $this->stockItem($product, 5);

        $this->expectException(\DomainException::class);

        InventoryLedger::applyDelivery($this->note($product, 30));
    }
}
