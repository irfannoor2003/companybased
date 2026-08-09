<?php

namespace App\Support;

use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\InventoryProductionOrder;
use App\Models\InventoryStock;
use App\Models\InventoryTransfer;
use App\Models\InventoryWriteOff;
use App\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Stock ledger: the single source of truth for on-hand quantities and every
 * movement that changes them. Quantities are stored/returned as rounded decimal
 * strings (never binary floats) and negative stock is never allowed.
 */
final class InventoryLedger
{
    public const TYPES = [
        'initial', 'adjustment', 'transfer_in', 'transfer_out',
        'write_off', 'production_in', 'production_out', 'purchase_in',
    ];

    /**
     * Current on-hand quantity for an item, optionally filtered to a warehouse.
     */
    public static function onHand(int $itemId, ?int $warehouseId = null): string
    {
        $query = InventoryStock::where('item_id', $itemId);

        if ($warehouseId !== null) {
            $query->where('warehouse_id', $warehouseId);
        }

        return self::round($query->sum('quantity'));
    }

    /**
     * On-hand quantity per warehouse for an item: [warehouse_id => quantity].
     *
     * @return array<int, string>
     */
    public static function stockByWarehouse(int $itemId): array
    {
        return InventoryStock::where('item_id', $itemId)
            ->get(['warehouse_id', 'quantity'])
            ->mapWithKeys(fn ($row) => [(int) $row->warehouse_id => (string) $row->quantity])
            ->all();
    }

    /**
     * Apply a signed quantity change to an item in a warehouse and record the
     * ledger entry. Throws DomainException if the change would go negative.
     */
    public static function adjust(
        int $itemId,
        int $warehouseId,
        string|int|float $quantityChange,
        string $type,
        ?Model $reference = null,
        ?string $note = null,
    ): void {
        DB::transaction(function () use ($itemId, $warehouseId, $quantityChange, $type, $reference, $note) {
            $change = (float) $quantityChange;

            if (abs($change) < 0.0005) {
                return;
            }

            $stock = InventoryStock::firstOrNew([
                'item_id' => $itemId,
                'warehouse_id' => $warehouseId,
            ]);

            $newQty = round((float) $stock->quantity + $change, 3);

            if ($newQty < 0) {
                throw new \DomainException('Insufficient stock — the movement would drive quantity below zero.');
            }

            $stock->quantity = $newQty;
            $stock->save();

            InventoryMovement::create([
                'item_id' => $itemId,
                'warehouse_id' => $warehouseId,
                'quantity_change' => round($change, 3),
                'movement_type' => $type,
                'reference_type' => $reference ? $reference->getMorphClass() : null,
                'reference_id' => $reference?->getKey(),
                'note' => $note,
            ]);
        });
    }

    public static function applyTransfer(InventoryTransfer $transfer): void
    {
        DB::transaction(function () use ($transfer) {
            foreach ($transfer->items as $item) {
                static::adjust($item->item_id, $transfer->from_warehouse_id, '-'.$item->quantity, 'transfer_out', $transfer, $transfer->number);
                static::adjust($item->item_id, $transfer->to_warehouse_id, $item->quantity, 'transfer_in', $transfer, $transfer->number);
            }
        });
    }

    public static function applyWriteOff(InventoryWriteOff $writeOff): void
    {
        DB::transaction(function () use ($writeOff) {
            foreach ($writeOff->items as $item) {
                static::adjust(
                    $item->item_id,
                    $writeOff->warehouse_id,
                    '-'.$item->quantity,
                    'write_off',
                    $writeOff,
                    $item->reason ?: $writeOff->reason,
                );
            }
        });
    }

    public static function applyProduction(InventoryProductionOrder $order): void
    {
        DB::transaction(function () use ($order) {
            foreach ($order->items as $component) {
                static::adjust($component->component_item_id, $order->warehouse_id, '-'.$component->quantity_used, 'production_out', $order, $order->number);
            }

            static::adjust($order->item_id, $order->warehouse_id, $order->quantity, 'production_in', $order, $order->number);
        });
    }

    /**
     * Receive goods from a purchase order into the configured warehouse.
     * Only products tracked as inventory items and orders with a warehouse
     * post stock; all other lines are skipped.
     */
    public static function applyPurchaseReceipt(PurchaseOrder $order): void
    {
        if (! $order->warehouse_id) {
            return;
        }

        $items = InventoryItem::query()->whereIn('product_id', $order->items->pluck('product_id'))->get()->keyBy('product_id');

        DB::transaction(function () use ($order, $items) {
            foreach ($order->items as $line) {
                if (! $line->product_id || ! isset($items[$line->product_id])) {
                    continue;
                }

                static::adjust(
                    $items[$line->product_id]->id,
                    $order->warehouse_id,
                    $line->qty,
                    'purchase_in',
                    $order,
                    $order->number,
                );
            }
        });
    }

    private static function round(string|int|float $value): string
    {
        return (string) round((float) $value, 3);
    }
}
