<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\SalesCustomer;
use App\Models\SalesDeliveryNote;
use App\Models\SalesOrder;
use App\Models\SalesStatusEvent;
use App\Services\TrackingService;
use App\Support\DocumentData;
use App\Support\ExportsCsv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DeliveryNoteController extends Controller
{
    use ExportsCsv;

    public function index(Request $request): View
    {
        $deliveryNotes = SalesDeliveryNote::query()
            ->with(['customer'])
            ->when($request->filled('search'), fn ($q) => $q->where('number', 'like', "%{$request->search}%"))
            ->when($request->filled('customer'), fn ($q) => $q->where('customer_id', $request->customer))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->latest('issue_date')
            ->paginate(20)
            ->withQueryString();

        $customers = SalesCustomer::query()->orderBy('company_name')->get();

        return view('sales.delivery_notes.index', compact('deliveryNotes', 'customers'));
    }

    public function create(Request $request): View
    {
        $customers = SalesCustomer::query()->orderBy('company_name')->get();
        $products = Product::query()->where('is_active', true)->orderBy('name')->get();

        $fromOrder = null;
        if ($request->filled('order')) {
            $fromOrder = SalesOrder::query()->with(['items.product'])->findOrFail($request->order);
        }

        return view('sales.delivery_notes.create', compact('customers', 'products', 'fromOrder'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

        $note = SalesDeliveryNote::create([
            'number' => next_document_number('delivery_note', 'DN'),
            'order_id' => $data['order_id'] ?? null,
            'customer_id' => $data['customer_id'],
            'issue_date' => $data['issue_date'],
            'status' => $data['status'],
            'shipping_address' => $data['shipping_address'] ?? null,
            'carrier' => $data['carrier'] ?? null,
            'tracking_number' => $data['tracking_number'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        $this->syncItems($note, $request->input('items', []));

        return redirect()->route('sales.delivery_notes.edit', $note)
            ->with('toasts', [['type' => 'success', 'message' => "Delivery note {$note->number} created."]]);
    }

    public function edit(SalesDeliveryNote $deliveryNote): View
    {
        $deliveryNote->load(['customer', 'items.product', 'order', 'statusEvents.user']);
        $customers = SalesCustomer::query()->orderBy('company_name')->get();
        $products = Product::query()->where('is_active', true)->orderBy('name')->get();

        return view('sales.delivery_notes.edit', compact('deliveryNote', 'customers', 'products'));
    }

    public function show(SalesDeliveryNote $deliveryNote): View
    {
        $deliveryNote->load(['customer', 'items.product', 'order']);

        return view('documents.show', DocumentData::build($deliveryNote));
    }

    public function pdf(SalesDeliveryNote $deliveryNote): StreamedResponse
    {
        $deliveryNote->load(['customer', 'items.product', 'order']);

        $html = view('pdf.document', DocumentData::build($deliveryNote))->render();

        $pdf = Pdf::loadHTML($html)->setPaper('a4', 'portrait');

        return response()->streamDownload(function () use ($pdf) {
            echo $pdf->output();
        }, 'delivery-note-'.$deliveryNote->number.'.pdf', [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="delivery-note-'.$deliveryNote->number.'.pdf"',
        ]);
    }

    public function update(Request $request, SalesDeliveryNote $deliveryNote): RedirectResponse
    {
        $data = $this->validateData($request);

        $deliveryNote->update([
            'order_id' => $data['order_id'] ?? null,
            'customer_id' => $data['customer_id'],
            'issue_date' => $data['issue_date'],
            'status' => $data['status'],
            'shipping_address' => $data['shipping_address'] ?? null,
            'carrier' => $data['carrier'] ?? null,
            'tracking_number' => $data['tracking_number'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        $this->syncItems($deliveryNote, $request->input('items', []));

        return back()->with('toasts', [['type' => 'success', 'message' => "Delivery note {$deliveryNote->number} updated."]]);
    }

    public function destroy(SalesDeliveryNote $deliveryNote): RedirectResponse
    {
        $number = $deliveryNote->number;
        $deliveryNote->delete();

        return redirect()->route('sales.delivery_notes.index')
            ->with('toasts', [['type' => 'success', 'message' => "Delivery note {$number} deleted."]]);
    }

    public function export(Request $request): StreamedResponse
    {
        $deliveryNotes = SalesDeliveryNote::query()
            ->with(['customer'])
            ->when($request->filled('search'), fn ($q) => $q->where('number', 'like', "%{$request->search}%"))
            ->when($request->filled('customer'), fn ($q) => $q->where('customer_id', $request->customer))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->latest('issue_date')
            ->get();

        return $this->streamCsv('delivery-notes-'.now()->format('Y-m-d').'.csv', ['Number', 'Customer', 'Order', 'Issue date', 'Status', 'Carrier', 'Tracking no.'], $deliveryNotes->map(fn (SalesDeliveryNote $n) => [
            $n->number,
            $n->customer?->company_name,
            $n->order?->number ?? '—',
            $n->issue_date?->format('Y-m-d'),
            ucfirst($n->status),
            $n->carrier,
            $n->tracking_number,
        ]));
    }

    public function updateStatus(Request $request, SalesDeliveryNote $deliveryNote): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(SalesDeliveryNote::statusOptions())],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        SalesStatusEvent::create([
            'trackable_type' => SalesDeliveryNote::class,
            'trackable_id' => $deliveryNote->id,
            'from_status' => $deliveryNote->status,
            'to_status' => $data['status'],
            'user_id' => auth()->id(),
            'note' => $data['note'] ?? null,
        ]);

        $deliveryNote->update(['status' => $data['status']]);

        // Notify the order's customer when the shipment reaches a user-facing state.
        if (in_array($data['status'], ['packed', 'shipped', 'delivered'], true)) {
            app(TrackingService::class)->notifyDelivery($deliveryNote, $data['status']);
        }

        return back()->with('toasts', [['type' => 'success', 'message' => "Delivery note {$deliveryNote->number} marked as {$data['status']}."]]);
    }

    private function syncItems(SalesDeliveryNote $note, array $rawItems): void
    {
        $cleaned = [];

        foreach ($rawItems as $item) {
            $description = trim((string) ($item['description'] ?? ''));
            $qty = (float) ($item['qty'] ?? 1);

            if ($description === '' || $qty <= 0) {
                continue;
            }

            $cleaned[] = [
                'product_id' => ! empty($item['product_id']) ? $item['product_id'] : null,
                'description' => $description,
                'qty' => $qty,
            ];
        }

        $note->items()->delete();
        $note->items()->createMany($cleaned);
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'order_id' => ['nullable', 'integer', Rule::exists('sales_orders', 'id')],
            'customer_id' => ['required', 'integer', Rule::exists('sales_customers', 'id')],
            'issue_date' => ['required', 'date'],
            'status' => ['required', Rule::in(SalesDeliveryNote::statusOptions())],
            'shipping_address' => ['nullable', 'string', 'max:1000'],
            'carrier' => ['nullable', 'string', 'max:120'],
            'tracking_number' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1'],
        ]);
    }
}
