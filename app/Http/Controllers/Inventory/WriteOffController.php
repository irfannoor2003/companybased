<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\InventoryWarehouse;
use App\Models\InventoryWriteOff;
use App\Support\ExportsCsv;
use App\Support\InventoryLedger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WriteOffController extends Controller
{
    use ExportsCsv;

    public function index(Request $request): View
    {
        $writeOffs = InventoryWriteOff::query()
            ->with(['warehouse'])
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('warehouse'), fn ($q) => $q->where('warehouse_id', $request->warehouse))
            ->latest('write_off_date')
            ->paginate(20)
            ->withQueryString();

        $warehouses = InventoryWarehouse::query()->orderBy('name')->get();

        return view('inventory.write_offs.index', compact('writeOffs', 'warehouses'));
    }

    public function create(): View
    {
        $warehouses = InventoryWarehouse::query()->where('is_active', true)->orderBy('name')->get();
        $items = InventoryItem::query()->with('product')->where('is_active', true)->orderBy('id')->get();

        return view('inventory.write_offs.create', compact('warehouses', 'items'));
    }

    public function show(InventoryWriteOff $writeOff): View
    {
        $writeOff->load(['warehouse', 'items.item.product']);

        return view('inventory.write_offs.show', compact('writeOff'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

        $writeOff = InventoryWriteOff::create([
            'number' => next_document_number('inventory_write_off', 'WO'),
            'warehouse_id' => $data['warehouse_id'],
            'write_off_date' => $data['write_off_date'],
            'reason' => $data['reason'] ?? null,
            'status' => $data['status'],
            'note' => $data['note'] ?? null,
        ]);

        $this->syncItems($writeOff, $request->input('items', []));

        return redirect()->route('inventory.write_offs.index')
            ->with('toasts', [['type' => 'success', 'message' => "Write-off {$writeOff->number} created."]]);
    }

    public function edit(InventoryWriteOff $writeOff): View
    {
        $writeOff->load(['warehouse', 'items.item.product']);
        $warehouses = InventoryWarehouse::query()->where('is_active', true)->orderBy('name')->get();
        $items = InventoryItem::query()->with('product')->where('is_active', true)->orderBy('id')->get();

        return view('inventory.write_offs.edit', compact('writeOff', 'warehouses', 'items'));
    }

    public function update(Request $request, InventoryWriteOff $writeOff): RedirectResponse
    {
        if (in_array($writeOff->status, ['completed', 'cancelled'])) {
            return back()->with('toasts', [['type' => 'error', 'message' => 'Completed or cancelled write-offs cannot be edited.']]);
        }

        $data = $this->validateData($request);

        $writeOff->update([
            'warehouse_id' => $data['warehouse_id'],
            'write_off_date' => $data['write_off_date'],
            'reason' => $data['reason'] ?? null,
            'status' => $data['status'],
            'note' => $data['note'] ?? null,
        ]);

        $this->syncItems($writeOff, $request->input('items', []));

        return back()->with('toasts', [['type' => 'success', 'message' => "Write-off {$writeOff->number} updated."]]);
    }

    public function updateStatus(Request $request, InventoryWriteOff $writeOff): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(InventoryWriteOff::statusOptions())],
        ]);

        if ($writeOff->status === 'completed') {
            return back()->with('toasts', [['type' => 'error', 'message' => 'This write-off is already completed.']]);
        }

        if ($data['status'] === 'completed') {
            if (! $writeOff->items()->exists()) {
                return back()->with('toasts', [['type' => 'error', 'message' => 'Add at least one item before completing this write-off.']]);
            }

            try {
                InventoryLedger::applyWriteOff($writeOff);
            } catch (\DomainException $e) {
                return back()->with('toasts', [['type' => 'error', 'message' => $e->getMessage()]]);
            }
        }

        $writeOff->update(['status' => $data['status']]);

        return back()->with('toasts', [['type' => 'success', 'message' => "Write-off {$writeOff->number} marked as {$data['status']}."]]);
    }

    public function destroy(InventoryWriteOff $writeOff): RedirectResponse
    {
        $number = $writeOff->number;
        $writeOff->delete();

        return redirect()->route('inventory.write_offs.index')
            ->with('toasts', [['type' => 'success', 'message' => "Write-off {$number} deleted."]]);
    }

    public function export(Request $request): StreamedResponse
    {
        $writeOffs = InventoryWriteOff::query()
            ->with(['warehouse'])
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->latest('write_off_date')
            ->get();

        return $this->streamCsv('write-offs-'.now()->format('Y-m-d').'.csv', ['Number', 'Warehouse', 'Date', 'Reason', 'Status', 'Note'], $writeOffs->map(fn (InventoryWriteOff $w) => [
            $w->number,
            $w->warehouse?->name,
            $w->write_off_date?->format('Y-m-d'),
            $w->reason,
            ucfirst($w->status),
            $w->note,
        ]));
    }

    private function syncItems(InventoryWriteOff $writeOff, array $rawItems): void
    {
        $cleaned = [];

        foreach ($rawItems as $item) {
            $quantity = (float) ($item['quantity'] ?? 0);

            if (empty($item['item_id']) || $quantity <= 0) {
                continue;
            }

            $cleaned[] = [
                'item_id' => $item['item_id'],
                'quantity' => round($quantity, 3),
                'reason' => $item['reason'] ?? null,
            ];
        }

        $writeOff->items()->delete();
        $writeOff->items()->createMany($cleaned);
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'warehouse_id' => ['required', 'integer', Rule::exists('inventory_warehouses', 'id')],
            'write_off_date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(InventoryWriteOff::statusOptions())],
            'note' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'integer', Rule::exists('inventory_items', 'id')],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
        ]);
    }
}
