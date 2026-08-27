<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\SalesCustomer;
use App\Models\SalesOrder;
use App\Models\SalesStatusEvent;
use App\Services\TrackingService;
use App\Support\DocumentData;
use App\Support\DocumentItems;
use App\Support\DiscountLimit;
use App\Support\ExportsCsv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderController extends Controller
{
    use ExportsCsv;
    use DiscountLimit;

    public function index(Request $request): View
    {
        $orders = SalesOrder::query()
            ->with(['customer'])
            ->withCount(['items', 'invoices'])
            ->when($request->filled('search'), fn ($q) => $q->where('number', 'like', "%{$request->search}%"))
            ->when($request->filled('customer'), fn ($q) => $q->where('customer_id', $request->customer))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->latest('issue_date')
            ->paginate(20)
            ->withQueryString();

        $customers = SalesCustomer::query()->orderBy('company_name')->get();

        return view('sales.orders.index', compact('orders', 'customers'));
    }

    public function create(): View
    {
        $customers = SalesCustomer::query()->orderBy('company_name')->get();
        $products = \App\Models\Product::query()->where('is_active', true)->orderBy('name')->get();
        $maxDiscount = $this->getMaxDiscountForUser();

        return view('sales.orders.create', compact('customers', 'products', 'maxDiscount'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);
        $this->validateDiscountLimits($request->input('items', []));

        $order = SalesOrder::create([
            'number' => next_document_number('order', 'SO'),
            'customer_id' => $data['customer_id'],
            'salesman_id' => auth()->id(),
            'issue_date' => $data['issue_date'],
            'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
            'status' => $data['status'],
            'currency' => $data['currency'] ?? null,
            'exchange_rate' => exchange_rate_for($data['currency'] ?? null),
            'shipping_address' => $data['shipping_address'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        $totals = DocumentItems::sync($order, $request->input('items', []));
        $order->update(['subtotal' => $totals['subtotal'], 'tax_amount' => $totals['tax'], 'total' => $totals['total']]);

        return redirect()->route('sales.orders.index')
            ->with('toasts', [['type' => 'success', 'message' => "Order {$order->number} created."]]);
    }

    public function edit(SalesOrder $order): View
    {
        $order->load(['customer', 'items.product', 'statusEvents.user', 'deliveryNotes']);
        $customers = SalesCustomer::query()->orderBy('company_name')->get();
        $products = \App\Models\Product::query()->where('is_active', true)->orderBy('name')->get();
        $maxDiscount = $this->getMaxDiscountForUser();

        return view('sales.orders.edit', compact('order', 'customers', 'products', 'maxDiscount'));
    }

    public function show(SalesOrder $order): View
    {
        $order->load(['customer', 'items.product']);

        return view('documents.show', DocumentData::build($order));
    }

    public function pdf(SalesOrder $order): StreamedResponse
    {
        $order->load(['customer', 'items.product']);

        $html = view('pdf.document', DocumentData::build($order))->render();

        $pdf = Pdf::loadHTML($html)->setPaper('a4', 'portrait');

        return $pdf->stream('order-'.$order->number.'.pdf');
    }

    public function update(Request $request, SalesOrder $order): RedirectResponse
    {
        $data = $this->validateData($request);
        $this->validateDiscountLimits($request->input('items', []));

        $order->update([
            'customer_id' => $data['customer_id'],
            'issue_date' => $data['issue_date'],
            'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
            'status' => $data['status'],
            'currency' => $data['currency'] ?? null,
            'exchange_rate' => exchange_rate_for($data['currency'] ?? null),
            'shipping_address' => $data['shipping_address'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        $totals = DocumentItems::sync($order, $request->input('items', []));
        $order->update(['subtotal' => $totals['subtotal'], 'tax_amount' => $totals['tax'], 'total' => $totals['total']]);

        return back()->with('toasts', [['type' => 'success', 'message' => "Order {$order->number} updated."]]);
    }

    public function destroy(SalesOrder $order): RedirectResponse
    {
        $number = $order->number;
        $order->delete();

        return redirect()->route('sales.orders.index')
            ->with('toasts', [['type' => 'success', 'message' => "Order {$number} deleted."]]);
    }

    public function export(Request $request): StreamedResponse
    {
        $orders = SalesOrder::query()
            ->with(['customer'])
            ->when($request->filled('search'), fn ($q) => $q->where('number', 'like', "%{$request->search}%"))
            ->when($request->filled('customer'), fn ($q) => $q->where('customer_id', $request->customer))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->latest('issue_date')
            ->get();

        return $this->streamCsv('orders-'.now()->format('Y-m-d').'.csv', ['Number', 'Customer', 'Issue date', 'Expected delivery', 'Status', 'Subtotal', 'Tax', 'Total'], $orders->map(fn (SalesOrder $o) => [
            $o->number,
            $o->customer?->company_name,
            $o->issue_date?->format('Y-m-d'),
            $o->expected_delivery_date?->format('Y-m-d'),
            ucfirst($o->status),
            $o->subtotal,
            $o->tax_amount,
            $o->total,
        ]));
    }

    public function confirm(SalesOrder $order): RedirectResponse
    {
        if ($order->isConfirmed()) {
            return back()->with('toasts', [['type' => 'danger', 'message' => 'Order already confirmed.']]);
        }

        $this->transition($order, 'confirmed', 'Order confirmed.');

        return back()->with('toasts', [['type' => 'success', 'message' => "Order {$order->number} confirmed."]]);
    }

    public function updateStatus(Request $request, SalesOrder $order): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(SalesOrder::statusOptions())],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->transition($order, $data['status'], $data['note'] ?? null);

        return back()->with('toasts', [['type' => 'success', 'message' => "Order {$order->number} marked as {$data['status']}."]]);
    }

    private function transition(SalesOrder $order, string $toStatus, ?string $note): void
    {
        $tracking = app(TrackingService::class);

        if ($toStatus === 'confirmed') {
            $tracking->ensureTrackingCode($order);
        }

        $tracking->recordTransition($order, $toStatus, $note);
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'customer_id' => ['required', 'integer', Rule::exists('sales_customers', 'id')],
            'issue_date' => ['required', 'date'],
            'expected_delivery_date' => ['nullable', 'date'],
            'status' => ['required', Rule::in(SalesOrder::statusOptions())],
            'currency' => ['nullable', 'string', 'max:8'],
            'shipping_address' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1'],
        ]);
    }
}
