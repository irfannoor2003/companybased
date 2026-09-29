<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\InventoryWarehouse;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseStatusEvent;
use App\Models\Supplier;
use App\Services\NotificationService;
use App\Support\DocumentData;
use App\Support\DocumentItems;
use App\Support\ExportsCsv;
use App\Support\InventoryLedger;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
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

        app(NotificationService::class)->notifyStaff(
            'suppliers.purchase_orders.view',
            'New purchase order',
            $order->number.' was raised with '.($order->supplier?->company_name ?? 'a supplier').' for '.money($order->total, $order->currency).'.',
            'info',
            route('suppliers.purchase_orders.show', $order),
            auth()->id(),
        );

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

    public function pdf(PurchaseOrder $order): Response
    {
        $order->load(['supplier', 'items.product']);

        $html = view('pdf.document', DocumentData::build($order))->render();

        $pdf = Pdf::loadHTML($html)->setPaper('a4', 'portrait');

        return $pdf->stream('purchase-order-'.$order->number.'.pdf');
    }

    public function update(Request $request, PurchaseOrder $order): RedirectResponse
    {
        if (in_array($order->status, ['partial_received', 'received', 'completed'], true)) {
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

    public function receive(Request $request, PurchaseOrder $order): RedirectResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer'],
            'items.*.received_qty' => ['required', 'numeric', 'min:0', 'decimal:0,3'],
        ]);

        try {
            $received = DB::transaction(function () use ($data, $order): array {
                $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);
                $items = $order->items()->lockForUpdate()->get()->keyBy('id');
                $requested = collect($data['items'])->keyBy('id');
                $deltas = [];

                if (! in_array($order->status, ['confirmed', 'sent', 'partial_received', 'received'], true)) {
                    throw new \DomainException('Only confirmed purchase orders can receive inventory.');
                }

                foreach ($requested as $itemId => $item) {
                    $line = $items->get((int) $itemId);

                    if (! $line) {
                        throw new \DomainException('One of the received lines does not belong to this purchase order.');
                    }

                    $target = round((float) $item['received_qty'], 3);
                    $current = round((float) $line->received_qty, 3);
                    $ordered = round((float) $line->qty, 3);

                    if ($target < $current || $target > $ordered) {
                        throw new \DomainException('Received quantities must be between the current received quantity and the ordered quantity.');
                    }

                    $delta = round($target - $current, 3);
                    if ($delta > 0) {
                        $deltas[$line->id] = $delta;
                        $line->update(['received_qty' => $target]);
                    }
                }

                if ($deltas !== []) {
                    $order->load('items');
                    InventoryLedger::applyPurchaseReceipt($order, $deltas);
                }

                $fullyReceived = $order->items()->get()->every(fn ($line) => (float) $line->received_qty >= (float) $line->qty);
                $newStatus = match (true) {
                    $fullyReceived => 'received',
                    $deltas !== [] => 'partial_received',
                    default => $order->status,
                };

                if ($newStatus !== $order->status) {
                    $this->transition($order, $newStatus, 'Purchase inventory received.');
                }

                return [$newStatus, $deltas];
            });
        } catch (\DomainException|\RuntimeException $e) {
            return back()->withInput()
                ->with('toasts', [['type' => 'danger', 'message' => $e->getMessage()]]);
        }

        $message = $received[1] === []
            ? 'No new inventory was received; the recorded quantities were already up to date.'
            : 'Inventory received and recorded.';

        if ($received[1] !== []) {
            app(NotificationService::class)->notifyStaff(
                'inventory.items.view',
                'Stock received on purchase order',
                'Purchase order '.$order->number.' added stock to the warehouse, moving it to '.$received[0].'.',
                'success',
                route('suppliers.purchase_orders.show', $order),
                auth()->id(),
            );
        }

        return back()->with('toasts', [['type' => 'success', 'message' => $message]]);
    }

    public function updateStatus(Request $request, PurchaseOrder $order): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(PurchaseOrder::statusOptions())],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $toStatus = $data['status'];

        if (in_array($toStatus, ['partial_received', 'received'], true)) {
            return back()->with('toasts', [['type' => 'danger', 'message' => 'Use Receive inventory to post partial or full receipts.']]);
        }

        if (in_array($toStatus, ['received', 'completed'], true) && ! in_array($order->status, ['confirmed', 'partial_received', 'sent'], true)) {
            return back()->with('toasts', [['type' => 'danger', 'message' => 'Only confirmed purchase orders can be received.']]);
        }

        $this->transition($order, $toStatus, $data['note'] ?? null);

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

        // Receipt transitions are reported by receive() as a stock movement,
        // so only the commercial statuses notify the purchasing team here.
        if (! in_array($toStatus, ['partial_received', 'received'], true)) {
            app(NotificationService::class)->notifyStaff(
                'suppliers.purchase_orders.view',
                'Purchase order '.($toStatus === 'confirmed' ? 'confirmed' : "marked as {$toStatus}"),
                'Order '.$order->number.' with '.($order->supplier?->company_name ?? 'a supplier').' is now '.$toStatus.'.',
                $toStatus === 'cancelled' ? 'warning' : 'success',
                route('suppliers.purchase_orders.show', $order),
                auth()->id(),
            );
        }
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
