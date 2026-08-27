<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\InventoryTransfer;
use App\Models\InventoryWarehouse;
use App\Support\ExportsCsv;
use App\Support\InventoryLedger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TransferController extends Controller
{
    use ExportsCsv;

    public function index(Request $request): View
    {
        $transfers = InventoryTransfer::query()
            ->with(['fromWarehouse', 'toWarehouse'])
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('warehouse'), fn ($q) => $q->where(function ($q) use ($request) {
                $q->where('from_warehouse_id', $request->warehouse)->orWhere('to_warehouse_id', $request->warehouse);
            }))
            ->latest('transfer_date')
            ->paginate(20)
            ->withQueryString();

        $warehouses = InventoryWarehouse::query()->orderBy('name')->get();

        return view('inventory.transfers.index', compact('transfers', 'warehouses'));
    }

    public function create(): View
    {
        $warehouses = InventoryWarehouse::query()->where('is_active', true)->orderBy('name')->get();
        $items = InventoryItem::query()->with('product')->where('is_active', true)->orderBy('id')->get();

        return view('inventory.transfers.create', compact('warehouses', 'items'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

        $transfer = InventoryTransfer::create([
            'number' => next_document_number('inventory_transfer', 'TRF'),
            'from_warehouse_id' => $data['from_warehouse_id'],
            'to_warehouse_id' => $data['to_warehouse_id'],
            'transfer_date' => $data['transfer_date'],
            'status' => $data['status'],
            'note' => $data['note'] ?? null,
        ]);

        $this->syncItems($transfer, $request->input('items', []));

        return redirect()->route('inventory.transfers.index')
            ->with('toasts', [['type' => 'success', 'message' => "Transfer {$transfer->number} created."]]);
    }

    public function edit(InventoryTransfer $transfer): View
    {
        $transfer->load(['fromWarehouse', 'toWarehouse', 'items.item.product']);
        $warehouses = InventoryWarehouse::query()->where('is_active', true)->orderBy('name')->get();
        $items = InventoryItem::query()->with('product')->where('is_active', true)->orderBy('id')->get();

        return view('inventory.transfers.edit', compact('transfer', 'warehouses', 'items'));
    }

    public function update(Request $request, InventoryTransfer $transfer): RedirectResponse
    {
        if (in_array($transfer->status, ['completed', 'cancelled'])) {
            return back()->with('toasts', [['type' => 'error', 'message' => 'Completed or cancelled transfers cannot be edited.']]);
        }

        $data = $this->validateData($request);

        $transfer->update([
            'from_warehouse_id' => $data['from_warehouse_id'],
            'to_warehouse_id' => $data['to_warehouse_id'],
            'transfer_date' => $data['transfer_date'],
            'status' => $data['status'],
            'note' => $data['note'] ?? null,
        ]);

        $this->syncItems($transfer, $request->input('items', []));

        return back()->with('toasts', [['type' => 'success', 'message' => "Transfer {$transfer->number} updated."]]);
    }

    public function updateStatus(Request $request, InventoryTransfer $transfer): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(InventoryTransfer::statusOptions())],
        ]);

        if ($transfer->status === 'completed') {
            return back()->with('toasts', [['type' => 'error', 'message' => 'This transfer is already completed.']]);
        }

        if ($data['status'] === 'completed') {
            if (! $transfer->items()->exists()) {
                return back()->with('toasts', [['type' => 'error', 'message' => 'Add at least one item before completing this transfer.']]);
            }

            try {
                InventoryLedger::applyTransfer($transfer);
            } catch (\DomainException $e) {
                return back()->with('toasts', [['type' => 'error', 'message' => $e->getMessage()]]);
            }
        }

        $transfer->update(['status' => $data['status']]);

        return back()->with('toasts', [['type' => 'success', 'message' => "Transfer {$transfer->number} marked as {$data['status']}."]]);
    }

    public function destroy(InventoryTransfer $transfer): RedirectResponse
    {
        $number = $transfer->number;
        $transfer->delete();

        return redirect()->route('inventory.transfers.index')
            ->with('toasts', [['type' => 'success', 'message' => "Transfer {$number} deleted."]]);
    }

    public function export(Request $request): StreamedResponse
    {
        $transfers = InventoryTransfer::query()
            ->with(['fromWarehouse', 'toWarehouse'])
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->latest('transfer_date')
            ->get();

        return $this->streamCsv('transfers-'.now()->format('Y-m-d').'.csv', ['Number', 'From', 'To', 'Date', 'Status', 'Note'], $transfers->map(fn (InventoryTransfer $t) => [
            $t->number,
            $t->fromWarehouse?->name,
            $t->toWarehouse?->name,
            $t->transfer_date?->format('Y-m-d'),
            ucfirst($t->status),
            $t->note,
        ]));
    }

    private function syncItems(InventoryTransfer $transfer, array $rawItems): void
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
                'notes' => $item['notes'] ?? null,
            ];
        }

        $transfer->items()->delete();
        $transfer->items()->createMany($cleaned);
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'from_warehouse_id' => ['required', 'integer', Rule::exists('inventory_warehouses', 'id')],
            'to_warehouse_id' => ['required', 'integer', 'different:from_warehouse_id', Rule::exists('inventory_warehouses', 'id')],
            'transfer_date' => ['required', 'date'],
            'status' => ['required', Rule::in(InventoryTransfer::statusOptions())],
            'note' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'integer', Rule::exists('inventory_items', 'id')],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
        ]);
    }
}
