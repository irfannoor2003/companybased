<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\InventoryBillOfMaterial;
use App\Models\InventoryItem;
use App\Models\InventoryWorkCentre;
use App\Support\ExportsCsv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BillOfMaterialController extends Controller
{
    use ExportsCsv;

    public function index(Request $request): View
    {
        $billOfMaterials = InventoryBillOfMaterial::query()
            ->with(['item.product'])
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('inventory.bill_of_materials.index', compact('billOfMaterials'));
    }

    public function create(): View
    {
        $items = InventoryItem::query()->with('product')->where('is_active', true)->orderBy('id')->get();
        $workCentres = InventoryWorkCentre::query()->where('is_active', true)->orderBy('code')->get();

        return view('inventory.bill_of_materials.create', compact('items', 'workCentres'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

        $bom = InventoryBillOfMaterial::create([
            'name' => $data['name'],
            'item_id' => $data['item_id'],
            'version' => $data['version'] ?? '1',
            'status' => $data['status'],
            'note' => $data['note'] ?? null,
        ]);

        $this->syncItems($bom, $request->input('items', []));
        $this->syncOperations($bom, $request->input('operations', []));

        return redirect()->route('inventory.bill_of_materials.index')
            ->with('toasts', [['type' => 'success', 'message' => "Bill of materials \"{$bom->name}\" created."]]);
    }

    public function edit(InventoryBillOfMaterial $billOfMaterial): View
    {
        $billOfMaterial->load(['item.product', 'items.componentItem.product', 'operations.workCentre']);
        $items = InventoryItem::query()->with('product')->where('is_active', true)->orderBy('id')->get();
        $workCentres = InventoryWorkCentre::query()->where('is_active', true)->orderBy('code')->get();

        return view('inventory.bill_of_materials.edit', compact('billOfMaterial', 'items', 'workCentres'));
    }

    public function update(Request $request, InventoryBillOfMaterial $billOfMaterial): RedirectResponse
    {
        $data = $this->validateData($request);

        $billOfMaterial->update([
            'name' => $data['name'],
            'item_id' => $data['item_id'],
            'version' => $data['version'] ?? '1',
            'status' => $data['status'],
            'note' => $data['note'] ?? null,
        ]);

        $this->syncItems($billOfMaterial, $request->input('items', []));
        $retained = $this->syncOperations($billOfMaterial, $request->input('operations', []));

        $message = "Bill of materials \"{$billOfMaterial->name}\" updated.";

        /**
         * An operation already on a job cannot be silently resurrected as a duplicate.
         *
         * Refusing the delete is the honest outcome: the routing line has been released
         * onto production orders and something on them has been worked against it, so
         * removing the definition would orphan a job in progress. Saying so beats
         * removing the row and leaving a finished order referencing a missing operation.
         */
        if ($retained > 0) {
            $message .= sprintf(
                ' %d operation(s) already released onto production orders with work recorded against them were kept — remove their work-centre time first if they must go.',
                $retained,
            );
        }

        return back()->with('toasts', [['type' => 'success', 'message' => $message]]);
    }

    public function destroy(InventoryBillOfMaterial $billOfMaterial): RedirectResponse
    {
        $name = $billOfMaterial->name;
        $billOfMaterial->delete();

        return redirect()->route('inventory.bill_of_materials.index')
            ->with('toasts', [['type' => 'success', 'message' => "Bill of materials \"{$name}\" deleted."]]);
    }

    public function export(Request $request): StreamedResponse
    {
        $billOfMaterials = InventoryBillOfMaterial::query()
            ->with(['item.product'])
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->latest('id')
            ->get();

        return $this->streamCsv('bill-of-materials-'.now()->format('Y-m-d').'.csv', ['Name', 'Finished item', 'Version', 'Components', 'Status', 'Note'], $billOfMaterials->map(fn (InventoryBillOfMaterial $b) => [
            $b->name,
            $b->item?->product?->name,
            $b->version,
            $b->items_count ?? $b->items()->count(),
            ucfirst($b->status),
            $b->note,
        ]));
    }

    private function syncItems(InventoryBillOfMaterial $bom, array $rawItems): void
    {
        $cleaned = [];

        foreach ($rawItems as $item) {
            $quantity = (float) ($item['quantity'] ?? 0);

            if (empty($item['item_id']) || $quantity <= 0) {
                continue;
            }

            if ((int) $item['item_id'] === (int) $bom->item_id) {
                continue;
            }

            $cleaned[] = [
                'component_item_id' => $item['item_id'],
                'quantity' => round($quantity, 3),
                'wastage_percent' => (float) ($item['wastage_percent'] ?? 0),
            ];
        }

        $bom->items()->delete();
        $bom->items()->createMany($cleaned);
    }

    /**
     * Reconcile a BOM's routing against what the editor submitted.
     *
     * A DIFF, not delete-and-recreate, and that distinction is load-bearing. A
     * production order copies the routing onto itself and keeps `bom_operation_id` on
     * each released row, so blowing the table away and re-inserting would give every
     * existing operation a new id: released jobs would point at operations that no
     * longer exist, and re-releasing a job could never match its own rows again.
     *
     * Rows are therefore matched on their submitted `id`, updated in place, and only
     * genuinely-removed rows are deleted -- and even then, not if work has been
     * recorded against them.
     *
     * Sequence is taken from array position rather than the submitted number, so a
     * user who deletes the middle of a five-step routing does not have to renumber the
     * rest by hand for the route to be coherent.
     *
     * @return int number of removed operations that were kept because a job has work against them
     */
    private function syncOperations(InventoryBillOfMaterial $bom, array $rawOperations): int
    {
        $existing = $bom->operations()->get()->keyBy('id');

        /**
         * Work out the target rows first, before writing anything.
         *
         * A submitted `id` is only honoured when that row actually belongs to THIS bom.
         * Otherwise one BOM's form could rewrite another's routing by guessing an id,
         * which is a straightforward authorisation hole in an id-addressed form.
         */
        $targets = [];
        $keptIds = [];

        foreach ($rawOperations as $row) {
            $workCentreId = $row['work_centre_id'] ?? null;

            if (empty($workCentreId)) {
                continue;
            }

            $id = isset($row['id']) ? (int) $row['id'] : 0;
            $operation = ($id > 0 && $existing->has($id)) ? $existing->get($id) : null;

            $targets[] = [
                'operation' => $operation,
                'attributes' => [
                    'work_centre_id' => (int) $workCentreId,
                    'name' => ($row['name'] ?? '') ?: null,
                    'setup_minutes' => round((float) ($row['setup_minutes'] ?? 0), 2),
                    'run_minutes_per_unit' => round((float) ($row['run_minutes_per_unit'] ?? 0), 4),
                    'notes' => ($row['notes'] ?? '') ?: null,
                ],
            ];

            if ($operation !== null) {
                $keptIds[] = $operation->id;
            }
        }

        /**
         * Clear sequence numbers that are about to be reused.
         *
         * `unique(bill_of_material_id, sequence)` means re-sequencing is not a simple
         * write: deleting step 2 of five to leave [1,3] renumbers the tail to [1,2], and
         * writing 2 while step 2 still holds it violates the index -- the request dies
         * on a duplicate key and the operator's edit is lost.
         *
         * So removals happen first, every surviving row is parked on a temporary high
         * sequence, and only then are the real numbers written. Nothing reads the
         * column mid-request.
         */
        $removals = [];
        $retained = [];

        foreach ($existing as $id => $operation) {
            if (in_array($id, $keptIds, true)) {
                continue;
            }

            /**
             * Reported time or a completed operation is real work somebody clocked.
             * The definition stays, and the caller is told, rather than the job
             * silently losing the step it was actually performed against.
             */
            $worked = $operation->orderOperations()
                ->where(function ($q) {
                    $q->where('minutes_spent', '>', 0)->orWhere('status', 'complete');
                })
                ->exists();

            if ($worked) {
                $retained[] = $operation;

                continue;
            }

            $removals[] = $operation;
        }

        foreach ($removals as $operation) {
            $operation->orderOperations()->delete();
            $operation->delete();
        }

        // Park every survivor out of the way of the numbers about to be written.
        $parkedFrom = 1000000;
        $parked = 0;

        foreach ($existing as $operation) {
            if (in_array($operation->id, $keptIds, true) || in_array($operation->id, array_map(fn ($o) => $o->id, $retained), true)) {
                $operation->forceFill(['sequence' => $parkedFrom + (++$parked)])->save();
            }
        }

        foreach ($targets as $index => $target) {
            $sequence = $index + 1;

            if ($target['operation'] !== null) {
                $target['operation']->forceFill($target['attributes'] + ['sequence' => $sequence])->save();

                continue;
            }

            $bom->operations()->create($target['attributes'] + ['sequence' => $sequence]);
        }

        /**
         * A retained operation keeps its place in the routing, at the end.
         *
         * It is still real work somebody performed, so it stays in the sequence — but it
         * cannot hold the number it used to, because that number now belongs to whichever
         * submitted step moved into it.
         */
        foreach ($retained as $offset => $operation) {
            $operation->forceFill(['sequence' => count($targets) + 1 + $offset])->save();
        }

        return count($retained);
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'item_id' => ['required', 'integer', Rule::exists('inventory_items', 'id')],
            'version' => ['nullable', 'string', 'max:40'],
            'status' => ['required', Rule::in(InventoryBillOfMaterial::statusOptions())],
            'note' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'integer', Rule::exists('inventory_items', 'id')],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.wastage_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],

            /**
             * The routing is optional on purpose. Plenty of products are assembled in
             * one step by hand and have no meaningful standard time, so requiring at
             * least one operation would force meaningless data onto them.
             */
            'operations' => ['nullable', 'array'],
            'operations.*.id' => ['nullable', 'integer'],
            /**
             * `whereNull('deleted_at')`, not a plain exists().
             *
             * A plain exists() matches a soft-deleted row, so a hand-constructed POST
             * could attach an operation to a retired work centre that the editor does
             * not offer -- recording a choice the operator could not actually have made.
             */
            'operations.*.work_centre_id' => [
                'nullable',
                'integer',
                Rule::exists('inventory_work_centres', 'id')->whereNull('deleted_at'),
            ],
            'operations.*.name' => ['nullable', 'string', 'max:255'],
            'operations.*.setup_minutes' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'operations.*.run_minutes_per_unit' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'operations.*.notes' => ['nullable', 'string', 'max:1000'],
        ]);
    }
}
