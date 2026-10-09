<?php

namespace Tests\Feature;

use App\Models\InventoryBillOfMaterial;
use App\Models\InventoryBillOfMaterialItem;
use App\Models\InventoryItem;
use App\Models\InventoryWarehouse;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\SalesOrderItem;
use App\Support\InventoryLedger;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * The material requirements screen.
 *
 * The planning arithmetic is covered in MaterialRequirementsTest. This covers
 * reachability, permission boundaries and what a user is actually shown, because a
 * correct calculation behind a 403 or an empty page is not a usable feature.
 */
class MaterialRequirementsUiTest extends TestCase
{
    use SeedsDatabase;

/**
     * Created lazily rather than in setUp().
     *
     * A setUp() here would shadow the trait's own setUp(), because a method declared
     * on the class always beats one pulled in by a trait. That silently skipped the
     * database seed and every role lookup failed.
     */
    private function warehouse(): InventoryWarehouse
    {
        return InventoryWarehouse::firstOrCreate(
            ['name' => 'MRP Warehouse'],
            ['code' => 'MRPW', 'is_active' => true],
        );
    }

    private function item(string $name): InventoryItem
    {
        $product = Product::create([
            'name' => $name,
            'sku' => 'MRP-'.strtoupper(substr(md5($name), 0, 6)),
            'cost_price' => '10.00',
            'retail_price' => '100.00',
            'is_active' => true,
        ]);

        return InventoryItem::firstOrCreate(
            ['product_id' => $product->id],
            ['warehouse_id' => $this->warehouse()->id, 'quantity_on_hand' => 0],
        );
    }

    private function seedDemand(): void
    {
        $finished = $this->item('MRP Chair');
        $steel = $this->item('MRP Steel');

        $bom = InventoryBillOfMaterial::create([
            'name' => 'MRP Chair BOM',
            'item_id' => $finished->id,
            'version' => '1',
            'status' => 'active',
        ]);

        InventoryBillOfMaterialItem::create([
            'bill_of_material_id' => $bom->id,
            'component_item_id' => $steel->id,
            'quantity' => '2',
            'wastage_percent' => '0',
        ]);

        $order = SalesOrder::create([
            'number' => 'SO-MRP-'.uniqid(),
            'customer_id' => $this->customer()->id,
            'issue_date' => now()->toDateString(),
            'expected_delivery_date' => now()->addWeeks(2)->toDateString(),
            'status' => 'confirmed',
            'currency' => 'PKR',
            'exchange_rate' => 1,
            'subtotal' => '1000', 'tax_amount' => '0', 'total' => '1000',
        ]);

        SalesOrderItem::create([
            'order_id' => $order->id,
            'product_id' => $finished->product_id,
            'description' => 'MRP Chair',
            'qty' => '10',
            'unit_price' => '100.00',
            'discount_percent' => '0',
            'tax_percent' => '0',
            'line_total' => '1000.00',
        ]);
    }

    public function test_the_screen_renders_with_no_demand(): void
    {
        $this->actingAs($this->userForRole('Admin'));

        $response = $this->get(route('inventory.material-requirements.index'));

        $response->assertOk();
        $response->assertSee('Material Requirements');
    }

    public function test_it_shows_a_shortage_against_confirmed_demand(): void
    {
        $this->actingAs($this->userForRole('Admin'));
        $this->seedDemand();

        $response = $this->get(route('inventory.material-requirements.index'));

        $response->assertOk();
        $response->assertSee('MRP Steel', escape: false);
    }

    /**
     * A plan nobody can reach is not a feature. The permission gate has to close
     * for a user without it.
     */
public function test_it_is_closed_to_a_user_without_the_permission(): void
    {
        /**
         * is_active must be true explicitly.
         *
         * EnsureUserIsActive logs out anyone whose account is not active and
         * redirects to login, so a user left at the default would be bounced to a
         * 302 and the test would pass for the wrong reason -- or fail obscurely,
         * which is what happened.
         */
        $user = new User([
            'name' => 'Store Clerk',
            'email' => 'mrp-clerk-'.uniqid().'@example.test',
            'password' => bcrypt('secret1234'),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $user->save();

        $this->actingAs($user);

        $this->get(route('inventory.material-requirements.index'))->assertForbidden();
    }

    public function test_the_horizon_can_be_narrowed(): void
    {
        $this->actingAs($this->userForRole('Admin'));
        $this->seedDemand();

        // Demand is dated a fortnight out; a same-day horizon must exclude it.
        $response = $this->get(route('inventory.material-requirements.index', [
            'horizon' => now()->toDateString(),
        ]));

        $response->assertOk();
        $response->assertDontSee('MRP Steel', escape: false);
    }

    public function test_stock_on_hand_covers_demand_in_the_view(): void
    {
        $this->actingAs($this->userForRole('Admin'));
        $this->seedDemand();

        $steel = InventoryItem::whereHas('product', fn ($q) => $q->where('name', 'MRP Steel'))->firstOrFail();
        InventoryLedger::adjust($steel->id, $this->warehouse()->id, '20', 'initial');

        $response = $this->get(route('inventory.material-requirements.index'));

        $response->assertOk();
        $response->assertSee('Covered', escape: false);
    }
}