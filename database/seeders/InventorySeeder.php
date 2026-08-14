<?php

namespace Database\Seeders;

use App\Models\InventoryBillOfMaterial;
use App\Models\InventoryIncomingShipment;
use App\Models\InventoryItem;
use App\Models\InventoryProductionOrder;
use App\Models\InventoryTransfer;
use App\Models\InventoryWarehouse;
use App\Models\InventoryWriteOff;
use App\Models\Product;
use App\Support\InventoryLedger;
use Illuminate\Database\Seeder;

class InventorySeeder extends Seeder
{
    /**
     * Demo inventory data: warehouses, tracked items, initial stock, one
     * completed transfer, one completed write-off, a BOM and a completed
     * production order. Quantities stay in decimal strings — never floats.
     */
    public function run(): void
    {
        if (InventoryWarehouse::exists() || InventoryItem::exists()) {
            return;
        }

        $main = InventoryWarehouse::create([
            'name' => 'Main Warehouse',
            'code' => 'MAIN',
            'address' => '1 Distribution Way, Anytown',
            'is_active' => true,
        ]);

        $north = InventoryWarehouse::create([
            'name' => 'North Branch',
            'code' => 'NORTH',
            'address' => '500 Cold Harbour Rd, Northside',
            'is_active' => true,
        ]);

        $products = Product::query()->get();
        $items = [];

        foreach ($products as $index => $product) {
            $reorder = (int) ($product->retail_price >= 100 ? 10 : 25);
            $initial = 40 + (($index * 13) % 60);

            $item = InventoryItem::create([
                'product_id' => $product->id,
                'reorder_level' => $reorder,
                'reorder_quantity' => $reorder * 2,
                'notes' => 'Seeded stock item.',
                'is_active' => true,
            ]);
            $items[] = $item;

            InventoryLedger::adjust($item->id, $main->id, $initial, 'initial', null, 'Opening balance (Seeder)');
            if ($index % 2 === 0) {
                InventoryLedger::adjust($item->id, $north->id, (int) ($initial / 3), 'initial', null, 'Opening balance (Seeder)');
            }
        }

        // Completed transfer: first item, 10 units from MAIN to NORTH.
        $transfer = InventoryTransfer::create([
            'number' => next_document_number('inventory_transfer', 'TRF'),
            'from_warehouse_id' => $main->id,
            'to_warehouse_id' => $north->id,
            'transfer_date' => now()->subDays(2)->toDateString(),
            'status' => 'draft',
            'note' => 'Seeded transfer.',
        ]);
        $transfer->items()->create([
            'item_id' => $items[0]->id,
            'quantity' => 10,
            'notes' => 'Restock north branch',
        ]);
        InventoryLedger::applyTransfer($transfer);
        $transfer->update(['status' => 'completed']);

        // Completed write-off: second item, 2 units damaged at MAIN.
        $writeOff = InventoryWriteOff::create([
            'number' => next_document_number('inventory_write_off', 'WO'),
            'warehouse_id' => $main->id,
            'write_off_date' => now()->subDays(1)->toDateString(),
            'reason' => 'Damaged in storage',
            'status' => 'draft',
            'note' => 'Seeded write-off.',
        ]);
        $writeOff->items()->create([
            'item_id' => $items[1]->id,
            'quantity' => 2,
            'reason' => 'Shelf collapse',
        ]);
        InventoryLedger::applyWriteOff($writeOff);
        $writeOff->update(['status' => 'completed']);

        // Bill of materials for the first item, using two other items.
        $bom = InventoryBillOfMaterial::create([
            'name' => $items[0]->product->name.' assembly',
            'item_id' => $items[0]->id,
            'version' => '1',
            'status' => 'active',
            'note' => 'Seeded BOM.',
        ]);
        $bom->items()->createMany([
            ['component_item_id' => $items[1]->id, 'quantity' => 2, 'wastage_percent' => 3],
            ['component_item_id' => $items[2]->id, 'quantity' => 1, 'wastage_percent' => 0],
        ]);

        // Completed production order producing 5 units of item 0 from the BOM.
        $order = InventoryProductionOrder::create([
            'number' => next_document_number('production_order', 'PO'),
            'item_id' => $items[0]->id,
            'bill_of_material_id' => $bom->id,
            'warehouse_id' => $main->id,
            'quantity' => 5,
            'scheduled_start_date' => now()->subDays(3)->toDateString(),
            'scheduled_end_date' => now()->subDays(1)->toDateString(),
            'status' => 'draft',
            'note' => 'Seeded production order.',
        ]);
        foreach ($bom->items as $component) {
            $order->items()->create([
                'component_item_id' => $component->component_item_id,
                'quantity_required' => (float) $component->quantity * 5,
                'quantity_used' => (float) $component->quantity * 5,
            ]);
        }
        InventoryLedger::applyProduction($order->fresh());
        $order->update(['status' => 'completed']);

        // One in-transit incoming shipment of 100 units of the first tracked
        // item, expected in 3 days, not yet approved (so the item detail page
        // shows "Incoming: 100 units").
        $shipment = InventoryIncomingShipment::create([
            'supplier_id' => null,
            'warehouse_id' => $main->id,
            'number' => next_document_number('incoming_shipment', 'INC'),
            'expected_arrival_at' => now()->addDays(3)->toDateString(),
            'status' => 'in_transit',
            'notes' => 'Seeded incoming shipment.',
        ]);
        $shipment->items()->create([
            'product_id' => $items[0]->product_id,
            'expected_quantity' => 100,
            'received_quantity' => 0,
            'unit_cost' => 12.50,
        ]);
    }
}
