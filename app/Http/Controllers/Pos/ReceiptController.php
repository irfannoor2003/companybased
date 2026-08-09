<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\PosSale;
use App\Support\ExportsCsv;
use App\Support\ExportsJson;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReceiptController extends Controller
{
    use ExportsCsv;
    use ExportsJson;

    public function index(Request $request): View
    {
        $sales = PosSale::query()
            ->with(['shift', 'paymentMethod'])
            ->withCount('items')
            ->when($request->filled('search'), fn ($q) => $q->where('receipt_number', 'like', "%{$request->search}%")->orWhere('customer_name', 'like', "%{$request->search}%"))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('sold_at', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('sold_at', '<=', $request->to))
            ->orderByDesc('sold_at')
            ->paginate(20)
            ->withQueryString();

        $total = round((float) PosSale::query()->where('status', 'completed')->sum('total'), 2);

        return view('pos.receipts.index', compact('sales', 'total'));
    }

    public function show(PosSale $sale): View
    {
        $sale->load(['shift.opener', 'paymentMethod', 'items']);

        return view('pos.receipts.show', compact('sale'));
    }

    public function export(Request $request): StreamedResponse
    {
        $sales = PosSale::query()
            ->with(['shift', 'paymentMethod'])
            ->when($request->filled('search'), fn ($q) => $q->where('receipt_number', 'like', "%{$request->search}%")->orWhere('customer_name', 'like', "%{$request->search}%"))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->orderByDesc('sold_at')
            ->get();

        $rows = $sales->map(fn (PosSale $s) => [
            'receipt' => $s->receipt_number,
            'shift' => $s->shift?->shift_number,
            'sold_at' => $s->sold_at?->format('Y-m-d H:i'),
            'customer' => $s->customer_name,
            'payment' => $s->paymentMethod?->name,
            'subtotal' => $s->subtotal,
            'discount' => $s->discount,
            'tax' => $s->tax,
            'total' => $s->total,
            'status' => ucfirst($s->status),
        ]);

        $filename = 'pos-receipts-'.now()->format('Y-m-d').'.'.($request->query('format') === 'json' ? 'json' : 'csv');

        return $request->query('format') === 'json'
            ? $this->streamJson($filename, $rows)
            : $this->streamCsv($filename, ['Receipt', 'Shift', 'Sold at', 'Customer', 'Payment', 'Subtotal', 'Discount', 'Tax', 'Total', 'Status'], $rows->values());
    }
}