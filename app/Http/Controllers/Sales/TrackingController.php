<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\SalesDeliveryNote;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Models\SalesStatusEvent;
use App\Services\TrackingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TrackingController extends Controller
{
    public function index(Request $request): View
    {
        $type = $request->filled('type') ? $request->type : null;

        $events = SalesStatusEvent::query()
            ->with(['user'])
            ->when($type === 'orders', fn ($q) => $q->where('trackable_type', SalesOrder::class))
            ->when($type === 'deliveries', fn ($q) => $q->where('trackable_type', SalesDeliveryNote::class))
            ->when($type === 'invoices', fn ($q) => $q->where('trackable_type', SalesInvoice::class))
            ->when($request->filled('search'), function ($q) use ($request) {
                $q->whereHasMorph('trackable', [SalesOrder::class, SalesDeliveryNote::class, SalesInvoice::class], function ($q) use ($request) {
                    $q->where('number', 'like', "%{$request->search}%");
                });
            })
            ->latest('created_at')
            ->paginate(30)
            ->withQueryString();

        return view('sales.tracking.index', compact('events', 'type'));
    }

    public function updateOrderStatus(Request $request, SalesOrder $order): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(SalesOrder::statusOptions())],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        app(TrackingService::class)->recordTransition($order, $data['status'], $data['note'] ?? null);

        return back()->with('toasts', [['type' => 'success', 'message' => "Order {$order->number} marked as {$data['status']}."]]);
    }

    public function updateDeliveryStatus(Request $request, SalesDeliveryNote $deliveryNote): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(SalesDeliveryNote::statusOptions())],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $service = app(TrackingService::class);
        $service->recordTransition($deliveryNote, $data['status'], $data['note'] ?? null);
        $service->notifyDelivery($deliveryNote, $data['status']);

        return back()->with('toasts', [['type' => 'success', 'message' => "Delivery note {$deliveryNote->number} marked as {$data['status']}."]]);
    }
}
