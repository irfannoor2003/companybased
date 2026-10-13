<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\InventoryBillOfMaterial;
use App\Models\InventoryItem;
use App\Models\InventoryProductionOrder;
use App\Models\InventoryWarehouse;
use App\Models\ProductionLabourEntry;
use App\Services\Accounting\ProductionAbsorptionService;
use App\Support\ExportsCsv;
use App\Support\InventoryLedger;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductionOrderController extends Controller
{
    use ExportsCsv;

    public function index(Request $request): View
    {
        $orders = InventoryProductionOrder::query()
            ->with(['item.product', 'warehouse', 'billOfMaterial'])
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('warehouse'), fn ($q) => $q->where('warehouse_id', $request->warehouse))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        $warehouses = InventoryWarehouse::query()->orderBy('name')->get();

        return view('inventory.production_orders.index', compact('orders', 'warehouses'));
    }

    public function create(): View
    {
        $items = InventoryItem::query()->with('product')->where('is_active', true)->orderBy('id')->get();
        $warehouses = InventoryWarehouse::query()->where('is_active', true)->orderBy('name')->get();
        $billOfMaterials = InventoryBillOfMaterial::query()->where('status', 'active')->with('item.product')->orderBy('name')->get();

        return view('inventory.production_orders.create', compact('items', 'warehouses', 'billOfMaterials'));
    }

    public function show(InventoryProductionOrder $order): View
    {
        $order->load(['item.product', 'warehouse', 'billOfMaterial', 'items.componentItem.product']);

        // Labour recorded against this order, so the cost capitalised into work in
        // progress is visible on the order that incurred it rather than only on the
        // trial balance.
        $labour = ProductionLabourEntry::query()
            ->where('production_order_id', $order->id)
            ->with('employee')
            ->orderBy('work_date')
            ->orderBy('id')
            ->get();

        // Only staff who can actually have their time recorded, so the form cannot
        // offer someone with no salary structure and then reject the submission.
        $employees = Employee::query()
            ->where('employment_status', 'active')
            ->orderBy('first_name')
            ->get();

        return view('inventory.production_orders.show', compact('order', 'labour', 'employees'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

        $order = InventoryProductionOrder::create([
            'number' => next_document_number('production_order', 'PO'),
            'item_id' => $data['item_id'],
            'bill_of_material_id' => $data['bill_of_material_id'] ?? null,
            'warehouse_id' => $data['warehouse_id'],
            'quantity' => $data['quantity'],
            'scheduled_start_date' => $data['scheduled_start_date'] ?? null,
            'scheduled_end_date' => $data['scheduled_end_date'] ?? null,
            'status' => $data['status'],
            'note' => $data['note'] ?? null,
        ]);

        $this->syncItems($order, $request->input('items', []));

        return redirect()->route('inventory.production_orders.index')
            ->with('toasts', [['type' => 'success', 'message' => "Production order {$order->number} created."]]);
    }

    public function edit(InventoryProductionOrder $order): View
    {
        $order->load(['item.product', 'warehouse', 'billOfMaterial', 'items.componentItem.product']);
        $items = InventoryItem::query()->with('product')->where('is_active', true)->orderBy('id')->get();
        $warehouses = InventoryWarehouse::query()->where('is_active', true)->orderBy('name')->get();
        $billOfMaterials = InventoryBillOfMaterial::query()->where('status', 'active')->with('item.product')->orderBy('name')->get();

        return view('inventory.production_orders.edit', compact('order', 'items', 'warehouses', 'billOfMaterials'));
    }

    public function update(Request $request, InventoryProductionOrder $order): RedirectResponse
    {
        if (in_array($order->status, ['completed', 'cancelled'])) {
            return back()->with('toasts', [['type' => 'error', 'message' => 'Completed or cancelled production orders cannot be edited.']]);
        }

        $data = $this->validateData($request);

        $order->update([
            'item_id' => $data['item_id'],
            'bill_of_material_id' => $data['bill_of_material_id'] ?? null,
            'warehouse_id' => $data['warehouse_id'],
            'quantity' => $data['quantity'],
            'scheduled_start_date' => $data['scheduled_start_date'] ?? null,
            'scheduled_end_date' => $data['scheduled_end_date'] ?? null,
            'status' => $data['status'],
            'note' => $data['note'] ?? null,
        ]);

        $this->syncItems($order, $request->input('items', []));

        return back()->with('toasts', [['type' => 'success', 'message' => "Production order {$order->number} updated."]]);
    }

    /**
     * Record time worked on an order, and capitalise it into work in progress.
     *
     * Absorption happens as the time is recorded, not at completion. That is the
     * whole point of a work in progress account: the cost exists while the job is
     * still running, so it sits in WIP until the goods are finished. Posting it all
     * at completion would make WIP permanently zero and the account decorative.
     */
    public function storeLabour(Request $request, InventoryProductionOrder $order): RedirectResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'work_date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        if (in_array($order->status, ['completed', 'cancelled'], true)) {
            return back()->with('toasts', [['type' => 'error', 'message' => 'This production order is closed.']]);
        }

        $service = app(ProductionAbsorptionService::class);
        $employee = Employee::findOrFail($data['employee_id']);

        /**
         * The rate is snapshotted onto the entry.
         *
         * Taken from the employee's salary as it stands today, because that is what
         * the hours were worth when worked. Storing it means a later pay rise cannot
         * silently restate labour already posted into inventory.
         */
        $rate = $service->hourlyRateFor($employee);

        if ($rate === null) {
            return back()->with('toasts', [[
                'type' => 'error',
                'message' => 'That employee has no salary structure, so their hourly rate is unknown.',
            ]]);
        }

        $minutes = (int) $data['minutes'];

        $entry = ProductionLabourEntry::create([
            'production_order_id' => $order->id,
            'employee_id' => $employee->id,
            'minutes' => $minutes,
            'hourly_rate' => $rate,
            'labour_cost' => $service->costForMinutes($minutes, $rate),
            'work_date' => $data['work_date'],
            'note' => $data['note'] ?? null,
        ]);

        try {
            $service->absorb($order->fresh());
        } catch (\RuntimeException $e) {
            return back()->with('toasts', [['type' => 'error', 'message' => $e->getMessage()]]);
        }

        return back()->with('toasts', [[
            'type' => 'success',
            'message' => 'Recorded '.Money::of($entry->labour_cost).' of labour and absorbed it into work in progress.',
        ]]);
    }

    public function updateStatus(Request $request, InventoryProductionOrder $order): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(InventoryProductionOrder::statusOptions())],

            /**
             * What the run actually produced.
             *
             * Optional, and optional-per-field on purpose. An order completed
             * without the shop floor filling this in keeps behaving exactly as it
             * did before, which is the point: reporting is new, and must not become
             * a new thing that can block completing a job.
             */
            'quantity_good' => ['nullable', 'numeric', 'min:0'],
            'quantity_scrap' => ['nullable', 'numeric', 'min:0'],
            'actuals' => ['nullable', 'array'],
            'actuals.*.component_item_id' => ['required', 'integer'],
            'actuals.*.quantity_used' => ['nullable', 'numeric', 'min:0'],
            'actuals.*.quantity_scrapped' => ['nullable', 'numeric', 'min:0'],
            'actuals.*.scrap_reason' => ['nullable', 'string', 'max:255'],
        ]);

        if ($order->status === 'completed') {
            return back()->with('toasts', [['type' => 'error', 'message' => 'This production order is already completed.']]);
        }

        if ($data['status'] === 'completed') {
            if (! $order->items()->exists()) {
                return back()->with('toasts', [['type' => 'error', 'message' => 'No components are linked to this production order.']]);
            }

            /**
             * Record what was really produced and really consumed, then post the
             * stock movement against those figures.
             *
             * This replaces a single bulk `quantity_used = quantity_required`
             * update, which made actual consumption impossible to report: stock
             * written off during the run was recorded as consumed in full, so the
             * loss vanished from the inventory records.
             */
            $yield = app(\App\Services\Inventory\ProductionYieldService::class)->settle(
                $order,
                $data['actuals'] ?? [],
                isset($data['quantity_good']) ? (float) $data['quantity_good'] : null,
                isset($data['quantity_scrap']) ? (float) $data['quantity_scrap'] : 0.0,
            );

            try {
                InventoryLedger::applyProduction(
                    $order->fresh(),
                    Money::of($yield['quantity_good']),
                    $yield['unit_cost'],
                );

                /**
                 * Pick up any labour recorded but not yet absorbed BEFORE the goods
                 * come out.
                 *
                 * Completion transfers everything sitting in work in progress onto
                 * the finished goods. If a back-dated labour entry were still waiting
                 * to be absorbed, it would be stranded in WIP forever with no order
                 * left to move it -- a real balance on a real account that nothing
                 * would ever reconcile.
                 */
                app(ProductionAbsorptionService::class)->absorb($order->fresh());

                /**
                 * Post the accounting side of the same event.
                 *
                 * applyProduction() moves stock but never touched the ledger, so
                 * completing an order changed every stock figure on the system and
                 * none of the accounting ones: the components stayed on the balance
                 * sheet at their original cost, the finished goods had no cost
                 * basis at all, and the difference went nowhere.
                 *
                 * Inside the same try, so a posting failure leaves stock and
                 * accounts consistent with each other rather than one updated and
                 * the other not.
                 */
                app(\App\Services\Accounting\ProductionPostingService::class)
                    ->postCompleted($order->fresh());
            } catch (\DomainException $e) {
                return back()->with('toasts', [['type' => 'error', 'message' => $e->getMessage()]]);
            } catch (\RuntimeException $e) {
                // The chart of accounts lacks a WIP or variance account.
                return back()->with('toasts', [['type' => 'error', 'message' => $e->getMessage()]]);
            }
        }

        $order->update(['status' => $data['status']]);

        $message = "Production order {$order->number} marked as {$data['status']}.";

        if ($data['status'] === 'completed') {
            /**
             * Report the yield back. A run that rejected a third of its output is
             * the single most useful thing the screen can tell the person who just
             * finished it, and it used to be unrecordable.
             */
            $message .= sprintf(
                ' %s good, %s scrapped (%.1f%% yield).',
                rtrim(rtrim(number_format($yield['quantity_good'], 3), '0'), '.'),
                rtrim(rtrim(number_format($yield['quantity_scrap'], 3), '0'), '.'),
                $yield['yield_percent'],
            );
        }

        return back()->with('toasts', [['type' => 'success', 'message' => $message]]);
    }

    public function destroy(InventoryProductionOrder $order): RedirectResponse
    {
        $number = $order->number;
        $order->delete();

        return redirect()->route('inventory.production_orders.index')
            ->with('toasts', [['type' => 'success', 'message' => "Production order {$number} deleted."]]);
    }

    public function export(Request $request): StreamedResponse
    {
        $orders = InventoryProductionOrder::query()
            ->with(['item.product', 'warehouse'])
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->latest('id')
            ->get();

        return $this->streamCsv('production-orders-'.now()->format('Y-m-d').'.csv', ['Number', 'Item', 'Quantity', 'Warehouse', 'Start', 'End', 'Status', 'Note'], $orders->map(fn (InventoryProductionOrder $o) => [
            $o->number,
            $o->item?->product?->name,
            $o->quantity,
            $o->warehouse?->name,
            $o->scheduled_start_date?->format('Y-m-d'),
            $o->scheduled_end_date?->format('Y-m-d'),
            ucfirst($o->status),
            $o->note,
        ]));
    }

    private function syncItems(InventoryProductionOrder $order, array $rawItems): void
    {
        $order->items()->delete();

        /**
         * When a BOM is selected, components are derived from it.
         *
         * Through BomExplosionService, so a sub-assembly is expanded into the parts
         * below it. This previously read the BOM's own lines, which meant an order
         * for a product containing a sub-assembly consumed the SUB-ASSEMBLY: an item
         * that is never bought and usually never held, so the job could not be
         * costed, could not be completed against real stock, and its true component
         * requirements were nowhere in the system.
         *
         * It also ignored `wastage_percent`, which MRP applied at the top level.
         * The same BOM therefore planned for more material than the order consumed.
         */
        if ($order->bill_of_material_id && $order->billOfMaterial) {
            $explosion = app(\App\Services\Inventory\BomExplosionService::class);
            $leaves = $explosion->explode($order->billOfMaterial, (float) $order->quantity);

            if ($leaves === []) {
                throw new \DomainException(
                    'The selected bill of materials expands to no components, so there is nothing to produce. '
                    .implode(' ', $explosion->cycles()),
                );
            }

            /**
             * Collapse leaves that are the same component reached by two paths.
             *
             * Two sub-assemblies sharing a part is normal, and the requirement for
             * that part is the SUM of both -- but posting two component lines for
             * one item would double-count its availability and make the same stock
             * look committed twice.
             */
            $byComponent = [];

            foreach ($leaves as $leaf) {
                $id = (int) $leaf['item']->id;

                $byComponent[$id] ??= [
                    'component_item_id' => $id,
                    'quantity_required' => 0.0,
                    'deepest_level' => 0,
                    'paths' => [],
                ];

                $byComponent[$id]['quantity_required'] += (float) $leaf['gross'];
                $byComponent[$id]['deepest_level'] = max($byComponent[$id]['deepest_level'], (int) $leaf['level']);
                $byComponent[$id]['paths'][] = $leaf['path'];
            }

            foreach ($byComponent as $line) {
                $order->items()->create([
                    'component_item_id' => $line['component_item_id'],
                    'quantity_required' => round($line['quantity_required'], 3),
                    'quantity_used' => 0,
                    'quantity_scrapped' => 0,
                    // The path is kept on the line so the show page can say WHERE in
                    // the tree a component came from, which is the question anyone
                    // looking at a multi-level order actually has.
                    'scrap_reason' => null,
                ]);
            }

            return;
        }

        $cleaned = [];
        foreach ($rawItems as $item) {
            $quantity = (float) ($item['quantity'] ?? 0);

            if (empty($item['item_id']) || $quantity <= 0) {
                continue;
            }

            $cleaned[] = [
                'component_item_id' => $item['item_id'],
                'quantity_required' => round($quantity, 3),
                'quantity_used' => round((float) ($item['quantity_used'] ?? 0), 3),
            ];
        }

        $order->items()->createMany($cleaned);
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'item_id' => ['required', 'integer', Rule::exists('inventory_items', 'id')],
            'bill_of_material_id' => ['nullable', 'integer', Rule::exists('inventory_bill_of_materials', 'id')],
            'warehouse_id' => ['required', 'integer', Rule::exists('inventory_warehouses', 'id')],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'scheduled_start_date' => ['nullable', 'date'],
            'scheduled_end_date' => ['nullable', 'date', 'after_or_equal:scheduled_start_date'],
            'status' => ['required', Rule::in(InventoryProductionOrder::statusOptions())],
            'note' => ['nullable', 'string', 'max:5000'],
            'items' => ['nullable', 'array'],
            'items.*.item_id' => ['nullable', 'integer', Rule::exists('inventory_items', 'id')],
            'items.*.quantity' => ['nullable', 'numeric', 'gt:0'],
        ]);
    }
}
