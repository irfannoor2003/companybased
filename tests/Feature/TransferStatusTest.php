<?php

namespace Tests\Feature;

use App\Models\InventoryTransfer;
use App\Models\InventoryTransferItem;
use App\Support\InventoryLedger;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * Completing a transfer moves real stock, so the movement and the status flip
 * have to be one atomic step.
 *
 * TransferController::updateStatus() used to call applyTransfer() — which opens
 * and commits its own transaction — and then write the status separately. A
 * failure in between left the transfer in `draft` with stock already debited,
 * which is exactly the invariant InventoryLedgerTest exists to protect.
 */
class TransferStatusTest extends TestCase
{
    use SeedsDatabase;

    private function transfer(int $itemId, int $fromId, int $toId, string $quantity = '10'): InventoryTransfer
    {
        $transfer = InventoryTransfer::create([
            'number' => 'TRF-TEST',
            'from_warehouse_id' => $fromId,
            'to_warehouse_id' => $toId,
            'status' => 'draft',
            'transfer_date' => now()->toDateString(),
        ]);

        InventoryTransferItem::create([
            'transfer_id' => $transfer->id,
            'item_id' => $itemId,
            'quantity' => $quantity,
        ]);

        return $transfer->fresh();
    }

    public function test_completing_a_transfer_moves_stock_and_flips_status(): void
    {
        $main = $this->warehouse('Main');
        $branch = $this->warehouse('Branch');
        [, $item] = $this->productWithStockItem();

        InventoryLedger::adjust($item->id, $main->id, '100', 'initial');

        $transfer = $this->transfer($item->id, $main->id, $branch->id, '40');

        $this->actingAs($this->userForRole('Admin'))
            ->patch("/inventory/transfers/{$transfer->id}/status", ['status' => 'completed'])
            ->assertSessionHasNoErrors();

        $this->assertSame('60', InventoryLedger::onHand($item->id, $main->id));
        $this->assertSame('40', InventoryLedger::onHand($item->id, $branch->id));
        $this->assertSame('100', InventoryLedger::onHand($item->id), 'a transfer is stock-neutral overall');
        $this->assertSame('completed', $transfer->fresh()->status);
    }

    public function test_an_impossible_completion_leaves_both_stock_and_status_untouched(): void
    {
        $main = $this->warehouse('Main');
        $branch = $this->warehouse('Branch');
        [, $item] = $this->productWithStockItem();

        InventoryLedger::adjust($item->id, $main->id, '5', 'initial');

        // Asking to move 50 units when only 5 exist.
        $transfer = $this->transfer($item->id, $main->id, $branch->id, '50');

        $this->actingAs($this->userForRole('Admin'))
            ->patch("/inventory/transfers/{$transfer->id}/status", ['status' => 'completed'])
            ->assertSessionHas('toasts');

        $this->assertSame('5', InventoryLedger::onHand($item->id, $main->id), 'source stock must be intact');
        $this->assertSame('0', InventoryLedger::onHand($item->id, $branch->id), 'destination stock must be untouched');
        $this->assertSame('draft', $transfer->fresh()->status, 'a failed completion must not flip the status');
    }

    public function test_a_completed_transfer_cannot_be_completed_again(): void
    {
        $main = $this->warehouse('Main');
        $branch = $this->warehouse('Branch');
        [, $item] = $this->productWithStockItem();

        InventoryLedger::adjust($item->id, $main->id, '100', 'initial');

        $transfer = $this->transfer($item->id, $main->id, $branch->id, '40');

        $user = $this->userForRole('Admin');

        $this->actingAs($user)->patch("/inventory/transfers/{$transfer->id}/status", ['status' => 'completed']);
        $this->actingAs($user)->patch("/inventory/transfers/{$transfer->id}/status", ['status' => 'completed'])
            ->assertSessionHas('toasts');

        $this->assertSame('60', InventoryLedger::onHand($item->id, $main->id), 'stock must not move twice');
        $this->assertSame('40', InventoryLedger::onHand($item->id, $branch->id));
    }
}
