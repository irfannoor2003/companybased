<?php

namespace Tests\Feature;

use App\Models\InventoryBillOfMaterial;
use App\Models\InventoryBillOfMaterialItem;
use App\Models\InventoryItem;
use App\Models\InventoryProductionOrder;
use App\Models\InventoryProductionOrderItem;
use App\Models\InventoryWarehouse;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Services\Inventory\MaterialRequirementsService;
use App\Support\InventoryLedger;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * Material requirements planning.
 *
 * There was no planning of any kind. Demand from confirmed sales orders was never
 * compared against stock, bill of materials, or what was already on order -- so a
 * business found out it was short on material when the purchase order could no
 * longer arrive in time.
 *
 * What matters here is the ORDER of the arithmetic. On hand comes first, then
 * stock already on order, then quantities already promised to production. Each
 * step exists to prevent a specific, repeated waste:
 *
 *  - Ignoring on-hand plans material for stock already on the shelf.
 *  - Ignoring on-order buys the same steel twice.
 *  - Ignoring production commitments consumes the same components in two jobs.
 */
class MaterialRequirementsTest extends TestCase
{
    use SeedsDatabase;

    private ?InventoryWarehouse $warehouse = null;

    private function warehouse(): InventoryWarehouse
    {
        return $this->warehouse ??= InventoryWarehouse::firstOrCreate(
            ['name' => 'Planning'],
            ['code' => 'PLAN', 'is_active' => true],
        );
    }

    private function item(string $name, string $costPrice = '10.00'): InventoryItem
    {
        $product = Product::create([
            'name' => $name,
            'sku' => 'SKU-'.strtoupper(substr(md5($name), 0, 6)),
            'cost_price' => $costPrice,
            'retail_price' => '100.00',
            'is_active' => true,
        ]);

        return InventoryItem::firstOrCreate(
            ['product_id' => $product->id],
            ['warehouse_id' => $this->warehouse()->id, 'quantity_on_hand' => 0],
        );
    }

    private function bomFor(InventoryItem $finished, array $components): InventoryBillOfMaterial
    {
        $bom = InventoryBillOfMaterial::create([
            'name' => 'BOM '.$finished->product->name,
            'item_id' => $finished->id,
            'version' => '1',
            'status' => 'active',
        ]);

        foreach ($components as [$component, $qty, $wastage]) {
            InventoryBillOfMaterialItem::create([
                'bill_of_material_id' => $bom->id,
                'component_item_id' => $component->id,
                'quantity' => (string) $qty,
                'wastage_percent' => (string) $wastage,
            ]);
        }

        return $bom->fresh();
    }

    private function salesOrder(InventoryItem $item, float $qty, string $status = 'confirmed'): SalesOrder
    {
        $order = SalesOrder::create([
            'number' => 'SO-'.uniqid(),
            'customer_id' => $this->customer()->id,
            'issue_date' => now()->toDateString(),
            'expected_delivery_date' => now()->addMonth()->toDateString(),
            'status' => $status,
            'currency' => 'PKR',
            'exchange_rate' => 1,
            'subtotal' => (string) ($qty * 100),
            'tax_amount' => '0',
            'total' => (string) ($qty * 100),
        ]);

        SalesOrderItem::create([
            'order_id' => $order->id,
            'product_id' => $item->product_id,
            'description' => $item->product->name,
            'qty' => (string) $qty,
            'unit_price' => '100.00',
            'discount_percent' => '0',
            'tax_percent' => '0',
            'line_total' => (string) ($qty * 100),
        ]);

        return $order->fresh();
    }

    private function service(): MaterialRequirementsService
    {
        return app(MaterialRequirementsService::class);
    }

    /**
     * @return array<string, mixed> the first component line
     */
    private function firstLine(array $plan): array
    {
        return $plan['components'][0];
    }

    public function test_demand_comes_from_confirmed_sales_orders(): void
    {
        $item = $this->item('Demand Item');

        $this->salesOrder($item, 10);

        $this->assertEquals(10, $this->service()->demandFor($item));
    }

    /**
     * A draft order is not a commitment. Planning material for sales that may never
     * happen is how a planner loses the business's trust in the plan.
     */
    public function test_a_draft_order_is_not_demand(): void
    {
        $item = $this->item('Draft Item');

        $this->salesOrder($item, 10, status: 'draft');

        $this->assertEquals(0, $this->service()->demandFor($item));
    }

    public function test_a_delivered_order_is_no_longer_demand(): void
    {
        $item = $this->item('Delivered Item');

        $this->salesOrder($item, 10, status: 'delivered');

        $this->assertEquals(0, $this->service()->demandFor($item));
    }

    public function test_a_cancelled_order_is_not_demand(): void
    {
        $item = $this->item('Cancelled Item');

        $this->salesOrder($item, 10, status: 'cancelled');

        $this->assertEquals(0, $this->service()->demandFor($item));
    }

    /**
     * The core: demand explodes through the BOM into component requirements.
     */
    public function test_demand_explodes_through_the_bill_of_materials(): void
    {
        $finished = $this->item('Chair', '50.00');
        $steel = $this->item('Steel Tube', '8.00');

        $this->bomFor($finished, [[$steel, 2, 0]]);

        $this->salesOrder($finished, 10);

        $plan = $this->service()->requirementsFor($finished);
        $line = $this->firstLine($plan);

        $this->assertSame(20.0, $line['gross'], '10 chairs x 2 tubes each');
    }

    /**
     * Wastage is added to the requirement, not treated as rounding. 5% on 1000
     * metres is 50 metres of shortfall discovered after the order was placed.
     */
    public function test_wastage_is_added_to_the_requirement(): void
    {
        $finished = $this->item('Fabric Chair', '50.00');
        $cloth = $this->item('Cloth', '6.00');

        $this->bomFor($finished, [[$cloth, 10, 5]]);

        $this->salesOrder($finished, 10);

        $line = $this->firstLine($this->service()->requirementsFor($finished));

        $this->assertSame(105.0, $line['gross'], 'wastage must be added on top of the BOM quantity');
    }

    /**
     * Stock on the shelf is subtracted first. Planning material for stock already
     * owned is the most basic MRP error.
     */
    public function test_stock_on_hand_is_netted_off(): void
    {
        $finished = $this->item('Net Item', '50.00');
        $steel = $this->item('Net Steel', '8.00');

        $this->bomFor($finished, [[$steel, 2, 0]]);
        $this->salesOrder($finished, 10);

        InventoryLedger::adjust($steel->id, $this->warehouse()->id, '12', 'initial');

        $line = $this->firstLine($this->service()->requirementsFor($finished));

        $this->assertSame(12.0, $line['on_hand']);
        $this->assertEquals(8.0, $line['net'], '20 needed, 12 on hand, so 8 short');
        $this->assertTrue($line['is_short']);
    }

    public function test_stock_on_hand_covering_demand_means_no_shortage(): void
    {
        $finished = $this->item('Covered', '50.00');
        $steel = $this->item('Covered Steel', '8.00');

        $this->bomFor($finished, [[$steel, 2, 0]]);
        $this->salesOrder($finished, 10);

        InventoryLedger::adjust($steel->id, $this->warehouse()->id, '25', 'initial');

        $line = $this->firstLine($this->service()->requirementsFor($finished));

        $this->assertEquals(0.0, $line['net']);
        $this->assertFalse($line['is_short']);
    }

    /**
     * Stock already on a purchase order is subtracted, so the same material is not
     * bought twice.
     */
    public function test_stock_already_on_order_is_netted_off(): void
    {
        $finished = $this->item('OnOrder', '50.00');
        $steel = $this->item('OnOrder Steel', '8.00');

        $this->bomFor($finished, [[$steel, 2, 0]]);
        $this->salesOrder($finished, 10);

        $po = PurchaseOrder::create([
            'number' => 'PO-'.uniqid(),
            'supplier_id' => $this->supplier()->id,
            'order_date' => now()->toDateString(),
            'status' => 'sent',
            'currency' => 'PKR',
            'exchange_rate' => 1,
            'subtotal' => '0', 'tax_amount' => '0', 'total' => '0',
        ]);

        PurchaseOrderItem::create([
            'order_id' => $po->id,
            'product_id' => $steel->product_id,
            'description' => 'Steel',
            'qty' => '20',
            'received_qty' => '0',
            'unit_price' => '8.00',
            'discount_percent' => '0',
            'tax_percent' => '0',
            'line_total' => '160.00',
        ]);

        $line = $this->firstLine($this->service()->requirementsFor($finished));

        $this->assertSame(20.0, $line['on_order']);
        $this->assertEquals(0.0, $line['net'], 'material already on order was planned again');
    }

    /**
     * The qty/received_qty distinction matters: counting the full ordered quantity
     * ignores what has already arrived and understates the shortage.
     */
    public function test_partly_received_stock_counts_only_the_outstanding_part(): void
    {
        $finished = $this->item('PartRecv', '50.00');
        $steel = $this->item('PartRecv Steel', '8.00');

        $this->bomFor($finished, [[$steel, 2, 0]]);
        $this->salesOrder($finished, 10);

        $po = PurchaseOrder::create([
            'number' => 'PO-'.uniqid(),
            'supplier_id' => $this->supplier()->id,
            'order_date' => now()->toDateString(),
            'status' => 'partial_received',
            'currency' => 'PKR',
            'exchange_rate' => 1,
            'subtotal' => '0', 'tax_amount' => '0', 'total' => '0',
        ]);

        PurchaseOrderItem::create([
            'order_id' => $po->id,
            'product_id' => $steel->product_id,
            'description' => 'Steel',
            'qty' => '20',
            'received_qty' => '15',
            'unit_price' => '8.00',
            'discount_percent' => '0',
            'tax_percent' => '0',
            'line_total' => '160.00',
        ]);

        $line = $this->firstLine($this->service()->requirementsFor($finished));

        $this->assertSame(5.0, $line['on_order'], '15 already received should not count as outstanding');
        $this->assertEquals(15.0, $line['net']);
    }

    /**
     * Material promised to a production order is deducted. Planning it again would
     * consume the same components in two jobs.
     */
    public function test_material_committed_to_production_is_netted_off(): void
    {
        $finished = $this->item('Committed', '50.00');
        $steel = $this->item('Committed Steel', '8.00');

        $this->bomFor($finished, [[$steel, 2, 0]]);
        $this->salesOrder($finished, 10);

/**
         * Only 15 in stock against a requirement of 20, so there is a real
         * shortfall for the commitment to show up in. Stock comfortably above the
         * requirement would hide the effect entirely.
         */
        InventoryLedger::adjust($steel->id, $this->warehouse()->id, '15', 'initial');

        $production = InventoryProductionOrder::create([
            'number' => 'MO-'.uniqid(),
            'item_id' => $finished->id,
            'warehouse_id' => $this->warehouse()->id,
            'quantity' => '5',
            'status' => 'in_progress',
        ]);

        InventoryProductionOrderItem::create([
            'production_order_id' => $production->id,
            'component_item_id' => $steel->id,
            'quantity_required' => '10',
            'quantity_used' => '0',
        ]);

        $line = $this->firstLine($this->service()->requirementsFor($finished));

        $this->assertSame(10.0, $line['committed']);
        $this->assertEquals(15.0, $line['net'], '20 needed, 15 in stock less the 10 already committed');
    }

    /**
     * A completed production order has consumed its components, so they are no
     * longer a future commitment.
     */
    public function test_a_completed_production_order_is_no_longer_a_commitment(): void
    {
        $finished = $this->item('Done', '50.00');
        $steel = $this->item('Done Steel', '8.00');

        $this->bomFor($finished, [[$steel, 2, 0]]);
        $this->salesOrder($finished, 10);

        $production = InventoryProductionOrder::create([
            'number' => 'MO-'.uniqid(),
            'item_id' => $finished->id,
            'warehouse_id' => $this->warehouse()->id,
            'quantity' => '5',
            'status' => 'completed',
        ]);

        InventoryProductionOrderItem::create([
            'production_order_id' => $production->id,
            'component_item_id' => $steel->id,
            'quantity_required' => '10',
            'quantity_used' => '10',
        ]);

        $line = $this->firstLine($this->service()->requirementsFor($finished));

        $this->assertSame(0.0, $line['committed']);
        $this->assertEquals(20.0, $line['net']);
    }

    /**
     * Reorder level is separate from a shortage. Something fully covered can still
     * be worth topping up.
     */
    public function test_below_reorder_level_is_flagged_even_without_a_shortage(): void
    {
        $finished = $this->item('Reorder', '50.00');
        $steel = $this->item('Reorder Steel', '8.00');

        $steel->update(['reorder_level' => '50', 'reorder_quantity' => '100']);

        $this->bomFor($finished, [[$steel, 1, 0]]);
        $this->salesOrder($finished, 1);

        InventoryLedger::adjust($steel->id, $this->warehouse()->id, '10', 'initial');

        $line = $this->firstLine($this->service()->requirementsFor($finished));

        $this->assertFalse($line['is_short'], 'demand is covered');
        $this->assertTrue($line['below_reorder'], 'but stock is under its reorder level');
    }

    /**
     * Only an active BOM is used. A production order costed against an arbitrary
     * recipe would be unreproducible.
     */
    public function test_only_an_active_bom_is_used(): void
    {
        $finished = $this->item('Versions', '50.00');
        $steel = $this->item('Version Steel', '8.00');

        $this->bomFor($finished, [[$steel, 2, 0]]);

        $this->assertNotNull($this->service()->bomFor($finished));

        InventoryBillOfMaterial::where('item_id', $finished->id)->update(['status' => 'inactive']);

        $this->assertNull($this->service()->bomFor($finished));
    }

    public function test_the_plan_covers_every_item_with_demand(): void
    {
        $chair = $this->item('Plan Chair', '50.00');
        $steel = $this->item('Plan Steel', '8.00');
        $table_ = $this->item('Plan Table', '60.00');
        $wood = $this->item('Plan Wood', '12.00');

        $this->bomFor($chair, [[$steel, 2, 0]]);
        $this->bomFor($table_, [[$wood, 4, 0]]);

        $this->salesOrder($chair, 5);
        $this->salesOrder($table_, 3);

        $plan = $this->service()->plan();
        $summary = MaterialRequirementsService::summarise($plan);

        $this->assertSame(2, $summary['items'], 'both finished items should be planned');
        $this->assertSame(2, $summary['components']);
        $this->assertSame(2, $summary['shortages'], 'nothing is in stock, so both are short');
    }

    public function test_the_summary_prices_the_purchase_value(): void
    {
        $finished = $this->item('Priced', '50.00');
        $steel = $this->item('Priced Steel', '8.00');

        $this->bomFor($finished, [[$steel, 2, 0]]);
        $this->salesOrder($finished, 10);

        $summary = MaterialRequirementsService::summarise($this->service()->plan());

        // 20 tubes short at 8.00 each.
        $this->assertSame('160.00', $summary['purchase_value']);
    }

    /**
     * An item with no BOM is bought outright. It should not appear as a component
     * requirement, and it should not break the plan.
     */
    public function test_an_item_with_no_bom_is_not_exploded(): void
    {
        $bought = $this->item('Bought In', '25.00');

        $this->salesOrder($bought, 5);

        $plan = $this->service()->plan();

        $this->assertArrayNotHasKey($bought->id, $plan);
    }
}