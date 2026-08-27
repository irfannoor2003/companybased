<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\InventoryBillOfMaterial;
use App\Models\InventoryItem;
use App\Models\InventoryProductionOrder;
use App\Models\InventoryWarehouse;
use App\Support\ExportsCsv;
use App\Support\InventoryLedger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function updateStatus(Request $request, InventoryProductionOrder $order): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(InventoryProductionOrder::statusOptions())],
        ]);

        if ($order->status === 'completed') {
            return back()->with('toasts', [['type' => 'error', 'message' => 'This production order is already completed.']]);
        }

        if ($data['status'] === 'completed') {
            if (! $order->items()->exists()) {
                return back()->with('toasts', [['type' => 'error', 'message' => 'No components are linked to this production order.']]);
            }

            $order->items()->where('quantity_used', '<=', 0)->update(['quantity_used' => \DB::raw('quantity_required')]);

            try {
                InventoryLedger::applyProduction($order->fresh());
            } catch (\DomainException $e) {
                return back()->with('toasts', [['type' => 'error', 'message' => $e->getMessage()]]);
            }
        }

        $order->update(['status' => $data['status']]);

        return back()->with('toasts', [['type' => 'success', 'message' => "Production order {$order->number} marked as {$data['status']}."]]);
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

        // When a BOM is selected, components are derived from it.
        if ($order->bill_of_material_id && $order->billOfMaterial) {
            $order->billOfMaterial->items()->get()->each(function ($component) use ($order) {
                $order->items()->create([
                    'component_item_id' => $component->component_item_id,
                    'quantity_required' => round((float) $component->quantity * (float) $order->quantity, 3),
                    'quantity_used' => 0,
                ]);
            });

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
