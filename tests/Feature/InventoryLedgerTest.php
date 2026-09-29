<?php

namespace Tests\Feature;

use App\Models\InventoryMovement;
use App\Models\InventoryTransfer;
use App\Models\InventoryTransferItem;
use App\Models\InventoryWriteOff;
use App\Models\InventoryWriteOffItem;
use App\Support\InventoryLedger;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * The stock ledger is the single source of truth for every quantity change, so
 * these tests treat its invariants as non-negotiable: on-hand must equal the
 * sum of its movements, stock may never go negative, and decimal arithmetic
 * must not drift.
 */
class InventoryLedgerTest extends TestCase
{
    use SeedsDatabase;

    public function test_opening_stock_then_reduce_moves_on_hand_exactly(): void
    {
        $warehouse = $this->warehouse();
        [, $item] = $this->productWithStockItem();

        InventoryLedger::adjust($item->id, $warehouse->id, '100', 'initial');
        $this->assertSame('100', InventoryLedger::onHand($item->id));

        InventoryLedger::adjust($item->id, $warehouse->id, '-30', 'adjustment');
        $this->assertSame('70', InventoryLedger::onHand($item->id));

        InventoryLedger::adjust($item->id, $warehouse->id, '-20.5', 'write_off');
        $this->assertSame('49.5', InventoryLedger::onHand($item->id));
    }

    public function test_on_hand_equals_sum_of_movements(): void
    {
        $warehouse = $this->warehouse();
        [, $item] = $this->productWithStockItem();

        foreach (['100', '-10', '5.25', '-0.25', '200'] as $change) {
            InventoryLedger::adjust($item->id, $warehouse->id, $change, 'adjustment');
        }

        $sum = (float) InventoryMovement::where('item_id', $item->id)->sum('quantity_change');

        $this->assertEqualsWithDelta(
            (float) InventoryLedger::onHand($item->id),
            $sum,
            0.0005,
            'on-hand must always equal the sum of its ledger movements',
        );
    }

    public function test_negative_stock_is_rejected(): void
    {
        $warehouse = $this->warehouse();
        [, $item] = $this->productWithStockItem();

        InventoryLedger::adjust($item->id, $warehouse->id, '10', 'initial');

        $this->expectException(\DomainException::class);

        InventoryLedger::adjust($item->id, $warehouse->id, '-11', 'write_off');
    }

    public function test_rejected_movement_leaves_no_trace(): void
    {
        $warehouse = $this->warehouse();
        [, $item] = $this->productWithStockItem();

        InventoryLedger::adjust($item->id, $warehouse->id, '10', 'initial');
        $before = InventoryMovement::count();

        try {
            InventoryLedger::adjust($item->id, $warehouse->id, '-50', 'write_off');
            $this->fail('expected the movement to be rejected');
        } catch (\DomainException) {
            // expected
        }

        $this->assertSame('10', InventoryLedger::onHand($item->id), 'stock must be unchanged');
        $this->assertSame($before, InventoryMovement::count(), 'no movement row may be written');
    }

    public function test_fractional_quantities_do_not_drift(): void
    {
        $warehouse = $this->warehouse();
        [, $item] = $this->productWithStockItem();

        // 0.1 + 0.2 style drift would show up here as a float artefact.
        for ($i = 0; $i < 10; $i++) {
            InventoryLedger::adjust($item->id, $warehouse->id, '0.1', 'initial');
        }

        $this->assertSame('1', InventoryLedger::onHand($item->id), 'ten adds of 0.1 must equal exactly 1');
    }

    public function test_warehouse_breakdown_is_correct(): void
    {
        $main = $this->warehouse('Main');
        $branch = $this->warehouse('Branch');
        [, $item] = $this->productWithStockItem();

        InventoryLedger::adjust($item->id, $main->id, '100', 'initial');
        InventoryLedger::adjust($item->id, $branch->id, '25', 'initial');

        $byWarehouse = InventoryLedger::stockByWarehouse($item->id);

        // Quantities are stored on a 3-decimal column, so compare numerically
        // rather than string-wise.
        $this->assertEqualsWithDelta(100.0, (float) $byWarehouse[$main->id], 0.0005);
        $this->assertEqualsWithDelta(25.0, (float) $byWarehouse[$branch->id], 0.0005);
        $this->assertEqualsWithDelta(125.0, (float) InventoryLedger::onHand($item->id), 0.0005, 'total spans all warehouses');
        $this->assertEqualsWithDelta(100.0, (float) InventoryLedger::onHand($item->id, $main->id), 0.0005);
    }

    public function test_transfer_moves_stock_without_changing_the_total(): void
    {
        $main = $this->warehouse('Main');
        $branch = $this->warehouse('Branch');
        [, $item] = $this->productWithStockItem();

        InventoryLedger::adjust($item->id, $main->id, '100', 'initial');

        $transfer = InventoryTransfer::create([
            'number' => 'TR-1',
            'from_warehouse_id' => $main->id,
            'to_warehouse_id' => $branch->id,
            'status' => 'completed',
            'transfer_date' => now()->toDateString(),
        ]);

        InventoryTransferItem::create([
            'transfer_id' => $transfer->id,
            'item_id' => $item->id,
            'quantity' => '40',
        ]);

        InventoryLedger::applyTransfer($transfer->fresh());

        $this->assertSame('60', InventoryLedger::onHand($item->id, $main->id));
        $this->assertSame('40', InventoryLedger::onHand($item->id, $branch->id));
        $this->assertSame('100', InventoryLedger::onHand($item->id), 'a transfer is stock-neutral overall');
    }

    public function test_transfer_cannot_move_more_than_is_on_hand(): void
    {
        $main = $this->warehouse('Main');
        $branch = $this->warehouse('Branch');
        [, $item] = $this->productWithStockItem();

        InventoryLedger::adjust($item->id, $main->id, '10', 'initial');

        $transfer = InventoryTransfer::create([
            'number' => 'TR-2',
            'from_warehouse_id' => $main->id,
            'to_warehouse_id' => $branch->id,
            'status' => 'completed',
            'transfer_date' => now()->toDateString(),
        ]);

        InventoryTransferItem::create([
            'transfer_id' => $transfer->id,
            'item_id' => $item->id,
            'quantity' => '25',
        ]);

        $this->expectException(\DomainException::class);

        InventoryLedger::applyTransfer($transfer->fresh());
    }

    public function test_write_off_reduces_stock_and_records_the_reason(): void
    {
        $main = $this->warehouse('Main');
        [, $item] = $this->productWithStockItem();

        InventoryLedger::adjust($item->id, $main->id, '50', 'initial');

        $writeOff = InventoryWriteOff::create([
            'number' => 'WO-1',
            'warehouse_id' => $main->id,
            'status' => 'completed',
            'reason' => 'Damaged in transit',
            'write_off_date' => now()->toDateString(),
        ]);

        InventoryWriteOffItem::create([
            'write_off_id' => $writeOff->id,
            'item_id' => $item->id,
            'quantity' => '8',
            'reason' => 'Cracked',
        ]);

        InventoryLedger::applyWriteOff($writeOff->fresh());

        $this->assertSame('42', InventoryLedger::onHand($item->id));

        $movement = InventoryMovement::where('item_id', $item->id)
            ->where('movement_type', 'write_off')
            ->first();

        $this->assertNotNull($movement);
        $this->assertEqualsWithDelta(-8.0, (float) $movement->quantity_change, 0.0005);
        $this->assertSame('Cracked', $movement->note);
    }

    public function test_zero_change_movement_is_a_no_op(): void
    {
        $main = $this->warehouse('Main');
        [, $item] = $this->productWithStockItem();

        InventoryLedger::adjust($item->id, $main->id, '10', 'initial');
        $before = InventoryMovement::count();

        InventoryLedger::adjust($item->id, $main->id, '0', 'adjustment');
        InventoryLedger::adjust($item->id, $main->id, '0.0001', 'adjustment');

        $this->assertSame($before, InventoryMovement::count(), 'sub-quantum changes must not be recorded');
        $this->assertSame('10', InventoryLedger::onHand($item->id));
    }

    public function test_movement_records_the_reference_document(): void
    {
        $main = $this->warehouse('Main');
        [, $item] = $this->productWithStockItem();
        $customer = $this->customer();
        [$product] = $this->productWithStockItem('Sold Item');
        $order = $this->salesOrder($customer, $product, '2', '50.00');

        InventoryLedger::adjust($item->id, $main->id, '5', 'adjustment', $order, 'test note');

        $movement = InventoryMovement::where('item_id', $item->id)->first();

        $this->assertSame($order->getMorphClass(), $movement->reference_type);
        $this->assertSame($order->id, $movement->reference_id);
        $this->assertSame('test note', $movement->note);
    }

    public function test_many_sequential_adjustments_stay_exact(): void
    {
        $main = $this->warehouse('Main');
        [, $item] = $this->productWithStockItem();

        // Add 1 then remove 1, 200 times. Float arithmetic would drift here.
        for ($i = 0; $i < 200; $i++) {
            InventoryLedger::adjust($item->id, $main->id, '1', 'initial');
            InventoryLedger::adjust($item->id, $main->id, '-1', 'adjustment');
        }

        $this->assertSame('0', InventoryLedger::onHand($item->id));
    }
}
