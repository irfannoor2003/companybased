<?php

namespace App\Support;

use App\Models\InventoryIncomingShipment;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\InventoryProductionOrder;
use App\Models\InventoryStock;
use App\Models\InventoryTransfer;
use App\Models\InventoryWarehouse;
use App\Models\InventoryWriteOff;
use App\Models\PurchaseOrder;
use App\Models\SalesDeliveryNote;
use App\Services\LowStockService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
        'incoming_shipment', 'pos_sale',
        // Goods physically leaving the building on a delivery note. Distinct from
        // `pos_sale` because the two relieve stock for different reasons on
        // different documents, and reconciling a warehouse against movements needs
        // to tell "we shipped it" from "we sold it over the counter".
        'delivery_out', 'delivery_in',
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

            $stock = InventoryStock::query()
                ->where('item_id', $itemId)
                ->where('warehouse_id', $warehouseId)
                ->lockForUpdate()
                ->first();

            if (! $stock) {
                InventoryStock::query()->insertOrIgnore([
                    'item_id' => $itemId,
                    'warehouse_id' => $warehouseId,
                    'quantity' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $stock = InventoryStock::query()
                    ->where('item_id', $itemId)
                    ->where('warehouse_id', $warehouseId)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

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

            // Evaluate the reorder level only once the movement is committed, so
            // an alert can never be sent for stock that later rolls back.
            app(LowStockService::class)->checkItemAfterCommit($itemId);
        });
    }

    /**
     * Relief stock for goods that physically left on a delivery note.
     *
     * A delivery note is a *fulfilment* document: the goods went out. Creating one
     * without moving stock meant the warehouse kept showing inventory that had
     * already been loaded onto a van, and the only place it was ever relieved was
     * the COGS leg when the invoice happened to be raised -- which for
     * deliver-then-bill is after the goods are gone, and for a lost delivery is
     * never.
     *
     * Cost of goods sold is deliberately NOT touched here. This moves quantity, not
     * value; the P&L recognises cost when the invoice is raised, which is the same
     * rule the rest of the application follows. A delivery note therefore reduces
     * what is on the shelf without reducing what the P&L says was sold.
     *
     * `delivery` is called only when the note is actually dispatched -- a draft or
     * pending note is a promise, not a movement.
     */
    public static function applyDelivery(SalesDeliveryNote $note, bool $returned = false): void
    {
        DB::transaction(function () use ($note, $returned) {
            // Delivery notes carry no warehouse of their own, so the goods leave
            // the one active warehouse -- the same rule the POS screen uses. If
            // there is more than one warehouse this becomes ambiguous, so it is
            // stated rather than hidden: log which one was relieved.
            $warehouseId = InventoryWarehouse::query()
                ->where('is_active', true)
                ->orderBy('id')
                ->value('id');

            if ($warehouseId === null) {
                Log::warning("Delivery note {$note->number}: no active warehouse, stock not moved.");

                return;
            }

            foreach ($note->items as $item) {
                if ($item->product_id === null) {
                    continue; // A service line has no stock.
                }

                // The delivery line carries a product; the ledger is keyed on the
                // inventory item. Using the product id directly would adjust the
                // wrong row whenever the two ids differ, which is exactly the kind
                // of silent off-by-id that leaves stock unaccounted for.
                $inventoryItemId = InventoryItem::query()
                    ->where('product_id', $item->product_id)
                    ->value('id');

                if ($inventoryItemId === null) {
                    Log::warning("Delivery note {$note->number}: product {$item->product_id} has no inventory item, stock not moved.");

                    continue;
                }

                static::adjust(
                    $inventoryItemId,
                    $warehouseId,
                    // `qty`, not `quantity`: the delivery-note line column is
                    // `qty`. Reading a non-existent property yields null, which
                    // casts to 0, and `adjust()` treats a change under 0.0005 as a
                    // no-op -- so the whole delivery silently moved nothing.
                    ($returned ? '' : '-').$item->qty,
                    $returned ? 'delivery_in' : 'delivery_out',
                    $note,
                    $note->number,
                );
            }
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
    public static function applyPurchaseReceipt(PurchaseOrder $order, array $quantitiesByItemId): void
    {
        if (! $order->warehouse_id) {
            return;
        }

        $items = InventoryItem::query()
            ->whereIn('product_id', $order->items->pluck('product_id'))
            ->get()
            ->keyBy('product_id');

        foreach ($order->items as $line) {
            $quantity = (float) ($quantitiesByItemId[$line->id] ?? 0);

            if ($quantity <= 0 || ! $line->product_id || ! isset($items[$line->product_id])) {
                continue;
            }

            self::adjust(
                $items[$line->product_id]->id,
                $order->warehouse_id,
                $quantity,
                'purchase_in',
                $order,
                $order->number,
            );
        }
    }

    /**
     * Approve an incoming shipment: post each item's reviewed (received)
     * quantity into the destination warehouse as tracked stock, recording an
     * auditable movement per line. Products without an underlying inventory
     * item are skipped (they aren't stock-tracked), matching purchase receipts.
     */
    public static function applyIncomingShipment(InventoryIncomingShipment $shipment): void
    {
        if (! $shipment->warehouse_id) {
            return;
        }

        $items = InventoryItem::query()
            ->whereIn('product_id', $shipment->items->pluck('product_id')->all())
            ->get()
            ->keyBy('product_id');

        DB::transaction(function () use ($shipment, $items) {
            foreach ($shipment->items as $line) {
                $received = (float) $line->received_quantity;

                if ($received <= 0) {
                    continue;
                }

                if (! isset($items[$line->product_id])) {
                    continue;
                }

                static::adjust(
                    $items[$line->product_id]->id,
                    $shipment->warehouse_id,
                    $received,
                    'incoming_shipment',
                    $shipment,
                    'Received via shipment '.$shipment->number,
                );
            }
        });
    }

    private static function round(string|int|float $value): string
    {
        return (string) round((float) $value, 3);
    }
}
