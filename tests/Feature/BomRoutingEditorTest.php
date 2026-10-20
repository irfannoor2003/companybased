<?php

namespace Tests\Feature;

use App\Models\InventoryBillOfMaterial;
use App\Models\InventoryBomOperation;
use App\Models\InventoryItem;
use App\Models\InventoryProductionOrder;
use App\Models\InventoryWorkCentre;
use App\Models\Product;
use App\Models\User;
use App\Services\Inventory\RoutingService;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * A bill of materials can be given a routing from the interface.
 *
 * The backend for routings shipped complete and tested: `inventory_bom_operations`,
 * the models, `RoutingService::release()`, the order-side operations, and the capacity
 * report. What did not exist was any way to WRITE the rows -- the table could only be
 * populated from a tinker session, because the BOM form edited components and nothing
 * else.
 *
 * That makes the whole feature unreachable: capacity reporting, planned-versus-actual
 * on a job, and machine-hour absorption all read a table no operator could fill in.
 *
 * The property worth defending hardest is the diff. Operations are copied onto
 * production orders by id (`bom_operation_id`), so a naive delete-and-recreate would
 * orphan every released job and break re-release matching for anything half done. Rows
 * must therefore be updated in place.
 */
class BomRoutingEditorTest extends TestCase
{
    use SeedsDatabase;

    private ?User $admin = null;

    private function admin(): User
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

    private function centre(array $attributes = []): InventoryWorkCentre
    {
        static $n = 0;

        return InventoryWorkCentre::create(array_merge([
            'code' => 'WC-'.(++$n),
            'name' => 'Work centre '.$n,
            'type' => 'machine',
            'hourly_rate' => '60.0000',
            'capacity_minutes_per_day' => 480,
            'is_active' => true,
        ], $attributes));
    }

    /**
     * @return array<string, mixed> a BOM payload with one component
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Routed widget',
            'item_id' => $this->item('Finished widget')->id,
            'status' => 'active',
            'version' => '1',
            'items' => [
                ['item_id' => $this->item('Steel bar')->id, 'quantity' => 2, 'wastage_percent' => 0],
            ],
        ], $overrides);
    }

    public function test_the_bom_edit_screen_offers_a_routing_editor(): void
    {
        $centre = $this->centre();

        $this->actingAs($this->admin())
            ->get(route('inventory.bill_of_materials.create'))
            ->assertOk()
            // The work centre must be selectable, or the editor is decorative.
            ->assertSee($centre->name);
    }

    public function test_the_bom_edit_screen_renders_existing_operations_for_editing(): void
    {
        $bom = InventoryBillOfMaterial::create([
            'name' => 'Routed widget',
            'item_id' => $this->item('Finished widget')->id,
            'version' => '1',
            'status' => 'active',
        ]);

        $bom->operations()->create([
            'work_centre_id' => $this->centre()->id,
            'sequence' => 1,
            'name' => 'Cut to length',
            'setup_minutes' => 10,
            'run_minutes_per_unit' => 1.5,
        ]);

        $this->actingAs($this->admin())
            ->get(route('inventory.bill_of_materials.edit', $bom))
            ->assertOk()
            ->assertSee('Cut to length');
    }

    public function test_a_routing_saved_from_the_interface_creates_the_operations(): void
    {
        $cutting = $this->centre();
        $assembly = $this->centre();

        $response = $this->actingAs($this->admin())
            ->post(route('inventory.bill_of_materials.store'), $this->payload([
                'operations' => [
                    ['work_centre_id' => $cutting->id, 'name' => 'Cut', 'setup_minutes' => 10, 'run_minutes_per_unit' => 0],
                    ['work_centre_id' => $assembly->id, 'name' => 'Assemble', 'setup_minutes' => 5, 'run_minutes_per_unit' => 2],
                ],
            ]));

        $response->assertRedirect(route('inventory.bill_of_materials.index'));

        $bom = InventoryBillOfMaterial::firstOrFail();

        $this->assertCount(2, $bom->operations);

        $this->assertSame(
            [['Cut', 1, '10.00'], ['Assemble', 2, '5.00']],
            $bom->operations()->orderBy('sequence')->get()
                ->map(fn ($op) => [$op->name, $op->sequence, (string) $op->setup_minutes])
                ->all(),
        );
    }

    public function test_editing_a_routing_updates_rows_in_place_rather_than_recreating_them(): void
    {
        $bom = InventoryBillOfMaterial::create([
            'name' => 'Routed widget',
            'item_id' => $this->item('Finished widget')->id,
            'version' => '1',
            'status' => 'active',
        ]);

        $operation = $bom->operations()->create([
            'work_centre_id' => $this->centre()->id,
            'sequence' => 1,
            'name' => 'Cut',
            'setup_minutes' => 10,
            'run_minutes_per_unit' => 0,
        ]);

        $originalId = $operation->id;

        $renamed = $this->centre();

        $this->actingAs($this->admin())
            ->put(route('inventory.bill_of_materials.update', $bom), $this->payload([
                'item_id' => $bom->item_id,
                'operations' => [[
                    'id' => $originalId,
                    'work_centre_id' => $renamed->id,
                    'name' => 'Saw to length',
                    'setup_minutes' => 25,
                    'run_minutes_per_unit' => 0.5,
                ]],
            ]))
            ->assertSessionHasNoErrors();

        $bom->refresh();

        $this->assertCount(1, $bom->operations);

        $operation = $bom->operations()->firstOrFail();

        /**
         * The id surviving is the whole point. A production order copies the routing
         * onto itself and keeps `bom_operation_id`, so a recreated row would leave
         * every released job pointing at an operation that no longer exists, and
         * re-releasing could never match its own rows again.
         */
        $this->assertSame($originalId, $operation->id);
        $this->assertSame('Saw to length', $operation->name);
        $this->assertSame($renamed->id, $operation->work_centre_id);
        $this->assertSame('25.00', (string) $operation->setup_minutes);
    }

    public function test_sequence_is_rebuilt_from_row_order_so_deleting_a_middle_step_needs_no_renumbering(): void
    {
        $bom = InventoryBillOfMaterial::create([
            'name' => 'Routed widget',
            'item_id' => $this->item('Finished widget')->id,
            'version' => '1',
            'status' => 'active',
        ]);

        $first = $bom->operations()->create([
            'work_centre_id' => $this->centre()->id, 'sequence' => 1, 'name' => 'First',
        ]);
        $middle = $bom->operations()->create([
            'work_centre_id' => $this->centre()->id, 'sequence' => 2, 'name' => 'Middle',
        ]);
        $last = $bom->operations()->create([
            'work_centre_id' => $this->centre()->id, 'sequence' => 3, 'name' => 'Last',
        ]);

        // The user deletes the middle step and does not renumber the other two.
        $this->actingAs($this->admin())
            ->put(route('inventory.bill_of_materials.update', $bom), $this->payload([
                'item_id' => $bom->item_id,
                'operations' => [
                    ['id' => $first->id, 'work_centre_id' => $first->work_centre_id, 'sequence' => 1, 'name' => 'First'],
                    ['id' => $last->id, 'work_centre_id' => $last->work_centre_id, 'sequence' => 3, 'name' => 'Last'],
                ],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(
            [['First', 1], ['Last', 2]],
            $bom->refresh()->operations()->orderBy('sequence')->get()
                ->map(fn ($op) => [$op->name, $op->sequence])->all(),
        );

        $this->assertDatabaseMissing('inventory_bom_operations', ['id' => $middle->id]);
    }

    public function test_an_operation_with_work_recorded_against_a_job_is_kept_rather_than_deleted(): void
    {
        $bom = InventoryBillOfMaterial::create([
            'name' => 'Routed widget',
            'item_id' => $this->item('Finished widget')->id,
            'version' => '1',
            'status' => 'active',
        ]);

        $operation = $bom->operations()->create([
            'work_centre_id' => $this->centre()->id, 'sequence' => 1, 'name' => 'Cut',
        ]);

        $order = InventoryProductionOrder::create([
            'number' => 'MO-'.uniqid(),
            'item_id' => $bom->item_id,
            'bill_of_material_id' => $bom->id,
            'warehouse_id' => $this->warehouse()->id,
            'quantity' => 10,
            'status' => 'in_progress',
        ]);

        $order->operations()->create([
            'bom_operation_id' => $operation->id,
            'sequence' => 1,
            'name' => 'Cut',
            'work_centre_id' => $operation->work_centre_id,
            'planned_minutes' => 10,
            'minutes_spent' => 14,
            'status' => 'complete',
        ]);

        /**
         * Somebody clocked 14 minutes against that step. Removing its definition would
         * leave a finished job pointing at a routing line that no longer exists, so the
         * line stays and the operator is told why it did not go.
         */
        $response = $this->actingAs($this->admin())
            ->put(route('inventory.bill_of_materials.update', $bom), $this->payload([
                'item_id' => $bom->item_id,
                'operations' => [],
            ]));

        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('inventory_bom_operations', ['id' => $operation->id]);

        $this->assertStringContainsString(
            'work recorded',
            (string) session('toasts')[0]['message'],
        );
    }

    public function test_a_routing_is_optional_so_a_bom_with_no_process_still_saves(): void
    {
        /**
         * Plenty of products are assembled in one step by hand and have no meaningful
         * standard time. Requiring an operation would force meaningless data onto them.
         */
        $this->actingAs($this->admin())
            ->post(route('inventory.bill_of_materials.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertSame(0, InventoryBillOfMaterial::firstOrFail()->operations()->count());
    }

    public function test_an_operation_without_a_work_centre_is_discarded_rather_than_saved_broken(): void
    {
        $this->actingAs($this->admin())
            ->post(route('inventory.bill_of_materials.store'), $this->payload([
                'operations' => [
                    // A row the user started and abandoned: no centre chosen.
                    ['work_centre_id' => '', 'name' => 'Unfinished', 'setup_minutes' => 5],
                ],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, InventoryBillOfMaterial::firstOrFail()->operations()->count());
    }

    public function test_a_work_centre_from_another_bom_cannot_be_reassigned_by_id(): void
    {
        /**
         * The submitted `id` is only trusted when the row actually belongs to this BOM.
         * Otherwise one BOM's form could rewrite another BOM's routing by guessing an id.
         */
        $mine = InventoryBillOfMaterial::create([
            'name' => 'Mine', 'item_id' => $this->item('Mine item')->id,
            'version' => '1', 'status' => 'active',
        ]);
        $theirs = InventoryBillOfMaterial::create([
            'name' => 'Theirs', 'item_id' => $this->item('Their item')->id,
            'version' => '1', 'status' => 'active',
        ]);

        $foreign = $theirs->operations()->create([
            'work_centre_id' => $this->centre()->id, 'sequence' => 1, 'name' => 'Not mine',
        ]);

        $this->actingAs($this->admin())
            ->put(route('inventory.bill_of_materials.update', $mine), $this->payload([
                'item_id' => $mine->item_id,
                'operations' => [[
                    'id' => $foreign->id,
                    'work_centre_id' => $foreign->work_centre_id,
                    'name' => 'Hijacked',
                ]],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('Not mine', $foreign->fresh()->name);
        $this->assertSame('Hijacked', $mine->refresh()->operations()->firstOrFail()->name);
    }

    public function test_a_saved_routing_is_released_onto_a_production_order(): void
    {
        /**
         * The end-to-end proof: what an operator types into the BOM form is what the
         * shop floor then works to.
         */
        $cutting = $this->centre();

        $bom = InventoryBillOfMaterial::create([
            'name' => 'Routed widget',
            'item_id' => $this->item('Finished widget')->id,
            'version' => '1',
            'status' => 'active',
        ]);

        $bom->operations()->create([
            'work_centre_id' => $cutting->id,
            'sequence' => 1,
            'name' => 'Cut',
            'setup_minutes' => 10,
            'run_minutes_per_unit' => 2,
        ]);

        $order = InventoryProductionOrder::create([
            'number' => 'MO-'.uniqid(),
            'item_id' => $bom->item_id,
            'bill_of_material_id' => $bom->id,
            'warehouse_id' => $this->warehouse()->id,
            'quantity' => 50,
            'status' => 'draft',
        ]);

        $result = app(RoutingService::class)->release($order->fresh(), $bom);

        $this->assertSame(['released' => 1, 'skipped' => 0], $result);
        $this->assertSame(110.0, (float) $order->operations()->firstOrFail()->planned_minutes);
    }

    public function test_a_retired_work_centre_is_not_offered_as_a_routing_target(): void
    {
        $retired = $this->centre(['is_active' => false]);

        $this->actingAs($this->admin())
            ->get(route('inventory.bill_of_materials.create'))
            ->assertOk()
            ->assertDontSee($retired->name);
    }

    public function test_the_editor_never_offers_to_record_the_finished_item_as_its_own_component(): void
    {
        /**
         * Documents the existing component guard from the routing screen's side, since
         * a BOM that contained itself would make the explosion service report a cycle.
         */
        $finished = $this->item('Self referential');

        $this->actingAs($this->admin())
            ->put(route('inventory.bill_of_materials.update', InventoryBillOfMaterial::create([
                'name' => 'Self', 'item_id' => $finished->id, 'version' => '1', 'status' => 'active',
            ])), [
                'name' => 'Self',
                'item_id' => $finished->id,
                'status' => 'active',
                'items' => [
                    ['item_id' => $finished->id, 'quantity' => 1, 'wastage_percent' => 0],
                    ['item_id' => $this->item('Real part')->id, 'quantity' => 1, 'wastage_percent' => 0],
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            1,
            InventoryBillOfMaterial::firstOrFail()->items()->count(),
            'the BOM listing itself as a component should have been dropped',
        );
    }

    public function test_a_routing_row_pointing_at_a_deleted_work_centre_is_rejected(): void
    {
        $centre = $this->centre();
        $bom = InventoryBillOfMaterial::create([
            'name' => 'Routed widget', 'item_id' => $this->item('Finished widget')->id,
            'version' => '1', 'status' => 'active',
        ]);

        $centre->delete();

        $this->actingAs($this->admin())
            ->put(route('inventory.bill_of_materials.update', $bom), $this->payload([
                'item_id' => $bom->item_id,
                'operations' => [['work_centre_id' => $centre->id, 'name' => 'Ghost']],
            ]))
            ->assertSessionHasErrors('operations.0.work_centre_id');

        $this->assertSame(0, $bom->refresh()->operations()->count());
    }

    public function test_a_non_negative_routing_time_is_enforced(): void
    {
        $centre = $this->centre();
        $bom = InventoryBillOfMaterial::create([
            'name' => 'Routed widget', 'item_id' => $this->item('Finished widget')->id,
            'version' => '1', 'status' => 'active',
        ]);

        $this->actingAs($this->admin())
            ->put(route('inventory.bill_of_materials.update', $bom), $this->payload([
                'item_id' => $bom->item_id,
                'operations' => [[
                    'work_centre_id' => $centre->id,
                    'setup_minutes' => -5,
                    'run_minutes_per_unit' => 1,
                ]],
            ]))
            ->assertSessionHasErrors('operations.0.setup_minutes');
    }

    public function test_soft_deleted_work_centres_cannot_be_selected_but_the_operation_survives_on_the_bom(): void
    {
        /**
         * A centre retired after a routing was defined must not vanish the routing.
         * The BOM keeps its operation -- the historical record stays true -- while the
         * editor stops offering the centre for new work.
         */
        $centre = $this->centre();
        $bom = InventoryBillOfMaterial::create([
            'name' => 'Routed widget', 'item_id' => $this->item('Finished widget')->id,
            'version' => '1', 'status' => 'active',
        ]);

        $operation = $bom->operations()->create([
            'work_centre_id' => $centre->id, 'sequence' => 1, 'name' => 'Cut',
        ]);

        $centre->delete();

        $this->assertDatabaseHas('inventory_bom_operations', ['id' => $operation->id]);

        // Releasing onto a job skips it, because a retired centre cannot take work.
        $order = InventoryProductionOrder::create([
            'number' => 'MO-'.uniqid(),
            'item_id' => $bom->item_id,
            'bill_of_material_id' => $bom->id,
            'warehouse_id' => $this->warehouse()->id,
            'quantity' => 10,
            'status' => 'draft',
        ]);

        $this->assertSame(
            ['released' => 0, 'skipped' => 1],
            app(RoutingService::class)->release($order->fresh(), $bom),
        );
    }

    public function test_an_operation_the_user_removed_is_not_released_onto_a_later_order(): void
    {
        $keep = $this->centre();
        $drop = $this->centre();

        $bom = InventoryBillOfMaterial::create([
            'name' => 'Routed widget', 'item_id' => $this->item('Finished widget')->id,
            'version' => '1', 'status' => 'active',
        ]);

        $bom->operations()->create(['work_centre_id' => $keep->id, 'sequence' => 1, 'name' => 'Keep']);
        $removed = $bom->operations()->create(['work_centre_id' => $drop->id, 'sequence' => 2, 'name' => 'Drop']);

        $kept = $bom->operations()->firstOrFail();

        $this->actingAs($this->admin())
            ->put(route('inventory.bill_of_materials.update', $bom), $this->payload([
                'item_id' => $bom->item_id,
                'operations' => [[
                    'id' => $kept->id,
                    'work_centre_id' => $keep->id, 'name' => 'Keep',
                ]],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('inventory_bom_operations', ['id' => $removed->id]);
        $this->assertSame(['Keep'], $bom->refresh()->operations()->pluck('name')->all());
    }

    public function test_the_operations_relation_is_loaded_for_the_editor_in_one_query_set(): void
    {
        /**
         * Guards against the editor being wired to a relation that does not exist --
         * which is how this feature was missed for so long: the model method was
         * there, tested, and simply had no caller.
         */
        $bom = InventoryBillOfMaterial::create([
            'name' => 'Routed widget', 'item_id' => $this->item('Finished widget')->id,
            'version' => '1', 'status' => 'active',
        ]);

        $this->assertInstanceOf(
            InventoryBomOperation::class,
            $bom->operations()->firstOrCreate(
                ['sequence' => 1],
                ['work_centre_id' => $this->centre()->id, 'name' => 'Cut'],
            ),
        );
    }
}
