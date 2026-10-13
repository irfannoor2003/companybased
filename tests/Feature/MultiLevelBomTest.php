<?php

namespace Tests\Feature;

use App\Models\InventoryBillOfMaterial;
use App\Models\InventoryItem;
use App\Models\InventoryProductionOrder;
use App\Models\Product;
use App\Services\Inventory\BomExplosionService;
use App\Services\Inventory\MaterialRequirementsService;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * A bill of materials can contain another bill of materials.
 *
 * The data model could already EXPRESS a sub-assembly -- a component line points at
 * an inventory item, and that item can be the subject of a BOM of its own -- but
 * nothing read the second level. Every explosion was a single pass over the
 * top-level lines, so a product built from a sub-assembly planned to buy the
 * sub-assembly: an item no supplier sells, which therefore could not be turned into
 * a purchase order and could not be costed or completed against real stock.
 *
 * The part with the teeth is the cycle guard. A BOM can contain itself by accident,
 * and A-requires-B-requires-A is the likeliest way to get one. That is not an
 * error to be returned -- it is an unbounded recursion that exhausts memory and
 * takes the request process with it.
 */
class MultiLevelBomTest extends TestCase
{
    use SeedsDatabase;

    private ?\App\Models\User $admin = null;

    private function admin(): \App\Models\User
    {
        return $this->admin ??= $this->userForRole('Admin');
    }

    private function item(string $name): InventoryItem
    {
        $product = Product::create([
            'sku' => 'SKU-'.strtoupper(substr(md5($name.microtime()), 0, 8)),
            'name' => $name,
            'price' => 100.00,
            'cost_price' => '10.00',
            'is_active' => true,
            'track_inventory' => true,
        ]);

        return InventoryItem::create(['product_id' => $product->id, 'is_active' => true]);
    }

    /**
     * @param  array<int, array{0: InventoryItem, 1: float, 2?: float}>  $components
     */
    private function bom(InventoryItem $subject, array $components, float $wastage = 0.0): InventoryBillOfMaterial
    {
        $bom = InventoryBillOfMaterial::create([
            'name' => 'BOM for '.$subject->product->name,
            'item_id' => $subject->id,
            'version' => '1',
            'status' => 'active',
        ]);

        foreach ($components as $component) {
            $bom->items()->create([
                'component_item_id' => $component[0]->id,
                'quantity' => $component[1],
                'wastage_percent' => $component[2] ?? $wastage,
            ]);
        }

        return $bom->fresh();
    }

    /**
     * A two-level structure:
     *
     *   Chair  =  1 x Frame + 2 x Screw
     *   Frame  =  2 x Timber + 1 x Resin
     *
     * Exploding Chair for 10 must yield 20 timber, 10 resin, 20 screws -- and NOT
     * the Frame, which is a made thing rather than a bought one.
     */
    private function twoLevel(): array
    {
        $chair = $this->item('Chair');
        $frame = $this->item('Frame');
        $screw = $this->item('Screw');
        $timber = $this->item('Timber');
        $resin = $this->item('Resin');

        $this->bom($frame, [[$timber, 2.0], [$resin, 1.0]]);
        $chairBom = $this->bom($chair, [[$frame, 1.0], [$screw, 2.0]]);

        return compact('chair', 'frame', 'screw', 'timber', 'resin', 'chairBom');
    }

    public function test_a_sub_assembly_is_expanded_into_its_parts(): void
    {
        $parts = $this->twoLevel();

        $leaves = app(BomExplosionService::class)->explode($parts['chairBom'], 10);

        $byItem = [];
        foreach ($leaves as $leaf) {
            $byItem[$leaf['item']->id] = $leaf['gross'];
        }

        $this->assertSame(20.0, $byItem[$parts['timber']->id] ?? null, '10 frames need 20 timber');
        $this->assertSame(10.0, $byItem[$parts['resin']->id] ?? null, '10 frames need 10 resin');
        $this->assertSame(20.0, $byItem[$parts['screw']->id] ?? null, '10 chairs need 20 screws');

        // The sub-assembly itself must not appear: it is not something you buy.
        $this->assertArrayNotHasKey(
            $parts['frame']->id,
            $byItem,
            'a sub-assembly must be expanded, not reported as a thing to purchase',
        );
    }

    /**
     * The nesting level is reported so the tree can be shown and a long chain can be
     * spotted. Direct components sit at level 1; those reached through a
     * sub-assembly sit at level 2.
     */
    public function test_the_nesting_level_is_reported(): void
    {
        $parts = $this->twoLevel();

        $levels = [];

        foreach (app(BomExplosionService::class)->explode($parts['chairBom'], 1) as $leaf) {
            $levels[$leaf['item']->id] = $leaf['level'];
        }

        $this->assertSame(1, $levels[$parts['screw']->id] ?? null, 'screws are a direct component');
        $this->assertSame(2, $levels[$parts['timber']->id] ?? null, 'timber sits inside the frame');
        $this->assertSame(2, $levels[$parts['resin']->id] ?? null, 'resin sits inside the frame');
    }

    /**
     * Wastage applies at EVERY level, not only the top.
     *
     * A sub-assembly is built with its own loss. Ignoring that under-orders whatever
     * the sub-assembly is made of, which is how a shortage appears only once the
     * job is half built.
     */
    public function test_wastage_applies_at_every_level(): void
    {
        $chair = $this->item('Chair');
        $frame = $this->item('Frame');
        $timber = $this->item('Timber');

        // Frame loses 10% of its timber.
        $this->bom($frame, [[$timber, 2.0, 10.0]]);
        $chairBom = $this->bom($chair, [[$frame, 1.0]]);

        $leaves = app(BomExplosionService::class)->explode($chairBom, 1);

        // 2 timber + 10% = 2.2
        $this->assertSame(2.2, $leaves[0]['gross']);
    }

    /**
     * A single-level BOM must still work exactly as before. Multi-level support is
     * worthless if it changes the simple case.
     */
    public function test_a_single_level_bom_is_unchanged(): void
    {
        $panel = $this->item('Panel');
        $screw = $this->item('Screw');
        $bom = $this->bom($panel, [[$screw, 4.0]]);

        $leaves = app(BomExplosionService::class)->explode($bom, 10);

        $this->assertCount(1, $leaves);
        $this->assertSame($screw->id, $leaves[0]['item']->id);
        $this->assertSame(40.0, $leaves[0]['gross']);
        $this->assertSame(1, $leaves[0]['level']);
    }

    /**
     * The cycle guard.
     *
     * A requires B, B requires A. Without a guard this recurses until the process
     * dies; with one, the cycle is reported as a data error the owner must fix.
     */
    public function test_a_circular_bom_is_reported_rather_than_recursed_into(): void
    {
        $a = $this->item('Part A');
        $b = $this->item('Part B');

        // A needs B, B needs A.
        $this->bom($b, [[$a, 1.0]]);
        $bomA = $this->bom($a, [[$b, 1.0]]);

        $service = app(BomExplosionService::class);
        $leaves = $service->explode($bomA, 1);

        $cycles = $service->cycles();

        $this->assertNotEmpty($cycles, 'a circular BOM must be reported');
        $this->assertStringContainsString('own recipe', implode(' ', $cycles));
        $this->assertCount(0, $leaves, 'a circular branch yields nothing usable, and must not loop');
    }

    /**
     * A self-referencing BOM: the shortest possible cycle.
     */
    public function test_a_self_referencing_bom_is_reported(): void
    {
        $a = $this->item('Part A');
        $bom = $this->bom($a, [[$a, 2.0]]);

        $service = app(BomExplosionService::class);
        $leaves = $service->explode($bom, 1);

        $this->assertCount(0, $leaves);
        $this->assertNotEmpty($service->cycles());
    }

    /**
     * A cycle on ONE branch must not lose the branches that are fine.
     */
    public function test_a_cycle_on_one_branch_does_not_hide_the_healthy_ones(): void
    {
        $chair = $this->item('Chair');
        $loopA = $this->item('Loop A');
        $loopB = $this->item('Loop B');
        $screw = $this->item('Screw');

        $this->bom($loopB, [[$loopA, 1.0]]);
        $this->bom($loopA, [[$loopB, 1.0]]);
        $chairBom = $this->bom($chair, [[$loopA, 1.0], [$screw, 2.0]]);

        $service = app(BomExplosionService::class);
        $leaves = $service->explode($chairBom, 1);

        $this->assertNotEmpty($service->cycles(), 'the cycle must be reported');
        $this->assertCount(1, $leaves, 'the healthy branch must still resolve');
        $this->assertSame($screw->id, $leaves[0]['item']->id);
    }

    /**
     * MRP plans the purchasable parts, not the sub-assembly.
     */
    public function test_material_requirements_plan_the_parts_not_the_sub_assembly(): void
    {
        $parts = $this->twoLevel();

        $lines = app(MaterialRequirementsService::class)->explode($parts['chairBom'], 10);

        $items = array_map(fn ($line) => $line['item']->id, $lines);

        $this->assertContains($parts['timber']->id, $items);
        $this->assertContains($parts['resin']->id, $items);
        $this->assertNotContains(
            $parts['frame']->id,
            $items,
            'planning to buy a sub-assembly produces a requirement nobody can order',
        );
    }

    /**
     * A production order consumes the parts, not the sub-assembly. Before this it
     * consumed the sub-assembly, which is never bought and usually never held.
     */
    public function test_a_production_order_expands_the_sub_assembly(): void
    {
        $parts = $this->twoLevel();

        $this->actingAs($this->admin())
            ->post('/inventory/production-orders', [
                'item_id' => $parts['chair']->id,
                'bill_of_material_id' => $parts['chairBom']->id,
                'warehouse_id' => $this->warehouse()->id,
                'quantity' => 10,
                'status' => 'draft',
            ])
            ->assertSessionHasNoErrors();

        $order = InventoryProductionOrder::latest('id')->firstOrFail();

        $required = $order->items->sum('quantity_required');

        // 20 timber + 10 resin + 20 screws = 50 parts for 10 chairs.
        $this->assertEqualsWithDelta(50.0, (float) $required, 0.001);

        $componentIds = $order->items->pluck('component_item_id')->all();

        $this->assertContains($parts['timber']->id, $componentIds);
        $this->assertNotContains(
            $parts['frame']->id,
            $componentIds,
            'the order must consume the parts, not the sub-assembly',
        );
    }

    /**
     * Two branches sharing a part are one requirement, summed.
     *
     * Posting two lines for the same component would make one item's stock look
     * committed twice and understate availability.
     */
    public function test_a_component_reached_by_two_paths_is_a_single_summed_line(): void
    {
        $chair = $this->item('Chair');
        $legPair = $this->item('Leg pair');
        $screw = $this->item('Screw');

        // Both sub-assemblies need screws.
        $this->bom($legPair, [[$screw, 2.0]]);
        $chairBom = $this->bom($chair, [[$legPair, 2.0], [$screw, 4.0]]);

        $this->actingAs($this->admin())
            ->post('/inventory/production-orders', [
                'item_id' => $chair->id,
                'bill_of_material_id' => $chairBom->id,
                'warehouse_id' => $this->warehouse()->id,
                'quantity' => 1,
                'status' => 'draft',
            ])
            ->assertSessionHasNoErrors();

        $order = InventoryProductionOrder::latest('id')->firstOrFail();

        $screwLines = $order->items->filter(fn ($line) => (int) $line->component_item_id === $screw->id);

        $this->assertCount(1, $screwLines, 'one component must be one line');
        // 2 leg pairs x 2 screws = 4, plus 4 direct = 8.
        $this->assertEqualsWithDelta(8.0, (float) $screwLines->first()->quantity_required, 0.001);
    }

    /**
     * An inactive BOM is not a sub-assembly. A retired recipe must stop being
     * expanded the moment it is retired.
     */
    public function test_an_inactive_bom_is_not_expanded(): void
    {
        $chair = $this->item('Chair');
        $frame = $this->item('Frame');
        $screw = $this->item('Screw');

        $retired = $this->bom($frame, [[$screw, 2.0]]);
        $retired->update(['status' => 'inactive']);

        $chairBom = $this->bom($chair, [[$frame, 1.0]]);

        $leaves = app(BomExplosionService::class)->explode($chairBom, 1);

        // The frame comes back as a leaf because its recipe is retired -- which is
        // the honest answer: it must then be bought or stocked as a whole.
        $this->assertCount(1, $leaves);
        $this->assertSame($frame->id, $leaves[0]['item']->id);
    }
}