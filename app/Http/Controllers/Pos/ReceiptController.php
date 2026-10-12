<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\PosSale;
use App\Support\ExportsCsv;
use App\Support\ExportsJson;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
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

    /**
     * A printable receipt: an 80mm thermal roll by default, or A4 on request.
     *
     * POS receipts had no printable form at all -- the only view was an HTML
     * screen page, so a customer could not be handed a receipt and a sale could
     * not be reprinted once the till rolled off. Every other money document in the
     * system has a PDF.
     *
     * The 80mm default is the one a shop actually uses: the till printer is a
     * thermal roll, and `?size=a4` gives the wider copy for a customer who wants
     * one filed.
     */
    public function print(Request $request, PosSale $sale): Response
    {
        $sale->load(['shift.opener', 'paymentMethod', 'items']);

        $a4 = $request->query('size') === 'a4';

        $html = view('pos.receipts.print', ['sale' => $sale, 'narrow' => ! $a4])->render();

        $pdf = Pdf::loadHTML($html)->setOptions([
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
            'defaultFont' => 'DejaVu Sans',
        ]);

        /**
         * 80mm x 297mm.
         *
         * Width is the roll. Height is a full A4 of roll rather than a guess: a
         * thermal printer cuts to the printed length, and giving a short fixed
         * sheet would truncate a sale with enough lines on it. DomPDF cannot
         * reflow height to fit the content, so the sheet is generous and the
         * printer discards the tail.
         *
         * Points, because that is what setPaper() takes for a custom size:
         * 80mm x 2.8346 = 226.77pt.
         */
        $pdf->setPaper($a4 ? 'a4' : [0, 0, 226.77, 841.89], $a4 ? 'portrait' : 'portrait');

        return $pdf->download($this->filename($sale, $a4));
    }

    private function filename(PosSale $sale, bool $a4): string
    {
        $base = 'receipt-'.$sale->receipt_number;

        return $a4 ? $base.'.pdf' : $base.'-80mm.pdf';
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