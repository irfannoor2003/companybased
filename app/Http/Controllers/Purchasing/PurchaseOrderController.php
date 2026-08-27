<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\InventoryWarehouse;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseStatusEvent;
use App\Models\Supplier;
use App\Support\DocumentData;
use App\Support\DocumentItems;
use App\Support\ExportsCsv;
use App\Support\InventoryLedger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PurchaseOrderController extends Controller
{
    use ExportsCsv;

    public function index(Request $request): View
    {
        $orders = PurchaseOrder::query()
            ->with(['supplier'])
            ->withCount(['items', 'invoices'])
            ->when($request->filled('search'), fn ($q) => $q->where('number', 'like', "%{$request->search}%"))
            ->when($request->filled('supplier'), fn ($q) => $q->where('supplier_id', $request->supplier))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->latest('order_date')
            ->paginate(20)
            ->withQueryString();

        $suppliers = Supplier::query()->orderBy('company_name')->get();

        return view('suppliers.purchase_orders.index', compact('orders', 'suppliers'));
    }

    public function create(): View
    {
        $suppliers = Supplier::query()->orderBy('company_name')->get();
        $warehouses = InventoryWarehouse::query()->orderBy('name')->get();
        $products = Product::query()->where('is_active', true)->orderBy('name')->get();

        return view('suppliers.purchase_orders.create', compact('suppliers', 'warehouses', 'products'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

        $order = PurchaseOrder::create([
            'number' => next_document_number('purchase_order', 'POR'),
            'supplier_id' => $data['supplier_id'],
            'warehouse_id' => $data['warehouse_id'] ?? null,
            'order_date' => $data['order_date'],
            'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
            'status' => $data['status'],
            'currency' => $data['currency'] ?? null,
            'exchange_rate' => exchange_rate_for($data['currency'] ?? null),
            'shipping_address' => $data['shipping_address'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        $totals = DocumentItems::sync($order, $request->input('items', []));
        $order->update(['subtotal' => $totals['subtotal'], 'tax_amount' => $totals['tax'], 'total' => $totals['total']]);

        return redirect()->route('suppliers.purchase_orders.index')
            ->with('toasts', [['type' => 'success', 'message' => "Purchase order {$order->number} created."]]);
    }

    public function edit(PurchaseOrder $order): View
    {
        $order->load(['supplier', 'warehouse', 'items.product', 'statusEvents.user', 'invoices']);
        $suppliers = Supplier::query()->orderBy('company_name')->get();
        $warehouses = InventoryWarehouse::query()->orderBy('name')->get();
        $products = Product::query()->where('is_active', true)->orderBy('name')->get();

        return view('suppliers.purchase_orders.edit', compact('order', 'suppliers', 'warehouses', 'products'));
    }

    public function show(PurchaseOrder $order): View
    {
        $order->load(['supplier', 'items.product']);

        return view('documents.show', DocumentData::build($order));
    }

    public function pdf(PurchaseOrder $order): StreamedResponse
    {
        $order->load(['supplier', 'items.product']);

        $html = view('pdf.document', DocumentData::build($order))->render();

        $pdf = Pdf::loadHTML($html)->setPaper('a4', 'portrait');

        return $pdf->stream('purchase-order-'.$order->number.'.pdf');
    }

    public function update(Request $request, PurchaseOrder $order): RedirectResponse
    {
        if ($order->status === 'received' || $order->status === 'completed') {
            return back()->with('toasts', [['type' => 'danger', 'message' => 'Received orders are locked.']]);
        }

        $data = $this->validateData($request);

        $order->update([
            'supplier_id' => $data['supplier_id'],
            'warehouse_id' => $data['warehouse_id'] ?? null,
            'order_date' => $data['order_date'],
            'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
            'status' => $data['status'],
            'currency' => $data['currency'] ?? null,
            'exchange_rate' => exchange_rate_for($data['currency'] ?? null),
            'shipping_address' => $data['shipping_address'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        $totals = DocumentItems::sync($order, $request->input('items', []));
        $order->update(['subtotal' => $totals['subtotal'], 'tax_amount' => $totals['tax'], 'total' => $totals['total']]);

        return back()->with('toasts', [['type' => 'success', 'message' => "Purchase order {$order->number} updated."]]);
    }

    public function destroy(PurchaseOrder $order): RedirectResponse
    {
        $number = $order->number;
        $order->delete();

        return redirect()->route('suppliers.purchase_orders.index')
            ->with('toasts', [['type' => 'success', 'message' => "Purchase order {$number} deleted."]]);
    }

    public function export(Request $request): StreamedResponse
    {
        $orders = PurchaseOrder::query()
            ->with(['supplier'])
            ->when($request->filled('search'), fn ($q) => $q->where('number', 'like', "%{$request->search}%"))
            ->when($request->filled('supplier'), fn ($q) => $q->where('supplier_id', $request->supplier))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->latest('order_date')
            ->get();

        return $this->streamCsv('purchase-orders-'.now()->format('Y-m-d').'.csv', ['Number', 'Supplier', 'Order date', 'Expected delivery', 'Status', 'Subtotal', 'Tax', 'Total'], $orders->map(fn (PurchaseOrder $o) => [
            $o->number,
            $o->supplier?->company_name,
            $o->order_date?->format('Y-m-d'),
            $o->expected_delivery_date?->format('Y-m-d'),
            ucfirst($o->status),
            $o->subtotal,
            $o->tax_amount,
            $o->total,
        ]));
    }

    public function confirm(PurchaseOrder $order): RedirectResponse
    {
        if ($order->isConfirmed()) {
            return back()->with('toasts', [['type' => 'danger', 'message' => 'Purchase order already confirmed.']]);
        }

        $this->transition($order, 'confirmed', 'Purchase order confirmed.');

        return back()->with('toasts', [['type' => 'success', 'message' => "Purchase order {$order->number} confirmed."]]);
    }

    public function updateStatus(Request $request, PurchaseOrder $order): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(PurchaseOrder::statusOptions())],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $toStatus = $data['status'];

        if (in_array($toStatus, ['received', 'completed'], true) && ! in_array($order->status, ['confirmed', 'partial_received', 'sent'], true)) {
            return back()->with('toasts', [['type' => 'danger', 'message' => 'Only confirmed purchase orders can be received.']]);
        }

        $this->transition($order, $toStatus, $data['note'] ?? null);

        if (in_array($toStatus, ['received', 'completed'], true)) {
            try {
                InventoryLedger::applyPurchaseReceipt($order);
                $order->items()->update(['received_qty' => DB::raw('qty')]);
            } catch (\DomainException $e) {
                return back()->with('toasts', [['type' => 'danger', 'message' => $e->getMessage()]]);
            }
        }

        return back()->with('toasts', [['type' => 'success', 'message' => "Purchase order {$order->number} marked as {$toStatus}."]]);
    }

    private function transition(PurchaseOrder $order, string $toStatus, ?string $note): void
    {
        PurchaseStatusEvent::create([
            'trackable_type' => PurchaseOrder::class,
            'trackable_id' => $order->id,
            'from_status' => $order->status,
            'to_status' => $toStatus,
            'user_id' => auth()->id(),
            'note' => $note,
        ]);

        $order->update(['status' => $toStatus]);
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')],
            'warehouse_id' => ['nullable', 'integer', Rule::exists('inventory_warehouses', 'id')],
            'order_date' => ['required', 'date'],
            'expected_delivery_date' => ['nullable', 'date'],
            'status' => ['required', Rule::in(PurchaseOrder::statusOptions())],
            'currency' => ['nullable', 'string', 'max:8'],
            'shipping_address' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1'],
        ]);
    }
}
