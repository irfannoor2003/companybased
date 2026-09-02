<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\InventoryIncomingShipment;
use App\Models\InventoryWarehouse;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Support\ExportsCsv;
use App\Support\InventoryLedger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IncomingShipmentController extends Controller
{
    use ExportsCsv;

    public function index(Request $request): View
    {
        $shipments = InventoryIncomingShipment::query()
            ->with(['supplier', 'warehouse'])
            ->when($request->filled('search'), fn ($q) => $q->where('number', 'like', "%{$request->search}%"))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('warehouse'), fn ($q) => $q->where('warehouse_id', $request->warehouse))
            ->when($request->filled('overdue') && $request->boolean('overdue'), function ($q) {
                $q->where('status', '!=', 'approved')
                    ->whereDate('expected_arrival_at', '<', now()->toDateString());
            })
            ->latest('expected_arrival_at')
            ->paginate(20)
            ->withQueryString();

        $warehouses = InventoryWarehouse::query()->where('is_active', true)->orderBy('name')->get();

        return view('inventory.incoming-shipments.index', compact('shipments', 'warehouses'));
    }

    public function create(): View
    {
        $trackedProducts = Product::query()
            ->whereHas('inventoryItem')
            ->with('inventoryItem')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $suppliers = Supplier::query()->orderBy('company_name')->get();
        $warehouses = InventoryWarehouse::query()->where('is_active', true)->orderBy('name')->get();
        $purchaseOrders = PurchaseOrder::query()->orderBy('number')->get();

        return view('inventory.incoming-shipments.create', compact('trackedProducts', 'suppliers', 'warehouses', 'purchaseOrders'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

        $shipment = DB::transaction(function () use ($data) {
            $shipment = InventoryIncomingShipment::create([
                'supplier_id' => $data['supplier_id'] ?? null,
                'warehouse_id' => $data['warehouse_id'],
                'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'number' => next_document_number('incoming_shipment', 'INC'),
                'expected_arrival_at' => $data['expected_arrival_at'] ?? null,
                'status' => $data['status'] ?? 'pending',
                'notes' => $data['notes'] ?? null,
            ]);

            $this->syncItems($shipment, $request->input('items', []));

            return $shipment;
        });

        return redirect()->route('inventory.incoming_shipments.show', $shipment)
            ->with('toasts', [['type' => 'success', 'message' => "Incoming shipment {$shipment->number} created."]]);
    }

    public function show(InventoryIncomingShipment $shipment): View
    {
        $shipment->load(['supplier', 'warehouse', 'purchaseOrder', 'items.product']);

        return view('inventory.incoming-shipments.show', compact('shipment'));
    }

    public function edit(InventoryIncomingShipment $shipment): View
    {
        if ($shipment->isLocked()) {
            return redirect()->route('inventory.incoming_shipments.show', $shipment)
                ->with('toasts', [['type' => 'error', 'message' => 'Approved shipments cannot be edited.']]);
        }

        $shipment->load(['items.product']);

        $trackedProducts = Product::query()->whereHas('inventoryItem')->with('inventoryItem')->where('is_active', true)->orderBy('name')->get();
        $suppliers = Supplier::query()->orderBy('company_name')->get();
        $warehouses = InventoryWarehouse::query()->where('is_active', true)->orderBy('name')->get();
        $purchaseOrders = PurchaseOrder::query()->orderBy('number')->get();

        return view('inventory.incoming-shipments.edit', compact('shipment', 'trackedProducts', 'suppliers', 'warehouses', 'purchaseOrders'));
    }

    public function update(Request $request, InventoryIncomingShipment $shipment): RedirectResponse
    {
        if ($shipment->isLocked()) {
            return back()->with('toasts', [['type' => 'error', 'message' => 'Approved shipments cannot be edited.']]);
        }

        $data = $this->validateData($request, $shipment->id);

        DB::transaction(function () use ($shipment, $data, $request) {
            $shipment->update([
                'supplier_id' => $data['supplier_id'] ?? null,
                'warehouse_id' => $data['warehouse_id'],
                'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'expected_arrival_at' => $data['expected_arrival_at'] ?? null,
                'status' => $data['status'],
                'notes' => $data['notes'] ?? null,
            ]);

            $this->syncItems($shipment, $request->input('items', []));
        });

        if ($data['status'] === 'arrived' && ! $shipment->arrived_at) {
            $shipment->forceFill(['arrived_at' => now()])->save();
        }

        return redirect()->route('inventory.incoming_shipments.show', $shipment)
            ->with('toasts', [['type' => 'success', 'message' => "Incoming shipment {$shipment->number} updated."]]);
    }

    public function updateStatus(Request $request, InventoryIncomingShipment $shipment): RedirectResponse
    {
        if ($shipment->isLocked()) {
            return back()->with('toasts', [['type' => 'error', 'message' => 'Approved shipments cannot be edited.']]);
        }

        $data = $request->validate([
            'status' => ['required', Rule::in(InventoryIncomingShipment::statusOptions())],
        ]);

        $shipment->update([
            'status' => $data['status'],
            'arrived_at' => $data['status'] === 'arrived' && ! $shipment->arrived_at ? now() : $shipment->arrived_at,
        ]);

        return back()->with('toasts', [['type' => 'success', 'message' => "Shipment {$shipment->number} marked as {$data['status']}."]]);
    }

    public function approve(Request $request, InventoryIncomingShipment $shipment): RedirectResponse
    {
        if (! auth()->user()->can('inventory.incoming_shipments.approve')) {
            abort(403);
        }

        if ($shipment->status !== 'arrived') {
            return back()->with('toasts', [['type' => 'error', 'message' => 'Only an arrived shipment can be approved. Mark it as arrived first.']]);
        }

        $shipment->load(['items', 'warehouse']);

        if (! $shipment->items()->exists()) {
            return back()->with('toasts', [['type' => 'error', 'message' => 'Add at least one item before approving this shipment.']]);
        }

        try {
            DB::transaction(function () use ($shipment) {
                InventoryLedger::applyIncomingShipment($shipment);
                $shipment->forceFill(['status' => 'approved', 'approved_at' => now()])->save();
            });
        } catch (\DomainException $e) {
            return back()->with('toasts', [['type' => 'error', 'message' => $e->getMessage()]]);
        }

        return back()->with('toasts', [['type' => 'success', 'message' => "Shipment {$shipment->number} approved — stock received."]]);
    }

    public function destroy(InventoryIncomingShipment $shipment): RedirectResponse
    {
        if (in_array($shipment->status, ['approved'])) {
            return back()->with('toasts', [['type' => 'error', 'message' => 'Approved shipments cannot be deleted.']]);
        }

        $number = $shipment->number;
        $shipment->delete();

        return redirect()->route('inventory.incoming_shipments.index')
            ->with('toasts', [['type' => 'success', 'message' => "Incoming shipment $number deleted."]]);
    }

    public function export(Request $request): StreamedResponse
    {
        $shipments = InventoryIncomingShipment::query()
            ->with(['supplier', 'warehouse'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->boolean('overdue'), fn ($q) => $q->where('status', '!=', 'approved')->whereDate('expected_arrival_at', '<', now()->toDateString()))
            ->latest('expected_arrival_at')
            ->get();

        return $this->streamCsv('incoming-shipments-'.now()->format('Y-m-d').'.csv', ['Number', 'Supplier', 'Warehouse', 'Expected', 'Status', 'Note'], $shipments->map(fn (InventoryIncomingShipment $s) => [
            $s->number,
            $s->supplier?->company_name,
            $s->warehouse?->name,
            $s->expected_arrival_at?->format('Y-m-d'),
            ucfirst($s->status),
            $s->notes,
        ]));
    }

    private function syncItems(InventoryIncomingShipment $shipment, array $rawItems): void
    {
        $cleaned = [];

        foreach ($rawItems as $item) {
            $expected = (float) ($item['expected_quantity'] ?? 0);

            if (empty($item['product_id']) || $expected <= 0) {
                continue;
            }

            $cleaned[] = [
                'product_id' => $item['product_id'],
                'expected_quantity' => round($expected, 3),
                'received_quantity' => round((float) ($item['received_quantity'] ?? 0), 3),
                'unit_cost' => isset($item['unit_cost']) && $item['unit_cost'] !== '' ? (float) $item['unit_cost'] : null,
                'notes' => $item['notes'] ?? null,
            ];
        }

        $shipment->items()->delete();
        $shipment->items()->createMany($cleaned);
    }

    private function validateData(Request $request, ?int $ignoreShipmentId = null): array
    {
        return $request->validate([
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')],
            'warehouse_id' => ['required', 'integer', Rule::exists('inventory_warehouses', 'id')],
            'purchase_order_id' => ['nullable', 'integer', Rule::exists('purchase_orders', 'id')],
            'expected_arrival_at' => ['nullable', 'date'],
            'status' => ['required', Rule::in(InventoryIncomingShipment::statusOptions())],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')],
            'items.*.expected_quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.received_quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
        ]);
    }
}
