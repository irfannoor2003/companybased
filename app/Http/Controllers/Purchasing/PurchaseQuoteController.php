<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseQuote;
use App\Models\Supplier;
use App\Support\DocumentData;
use App\Support\DocumentItems;
use App\Support\ExportsCsv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PurchaseQuoteController extends Controller
{
    use ExportsCsv;

    public function index(Request $request): View
    {
        $quotes = PurchaseQuote::query()
            ->with(['supplier'])
            ->when($request->filled('search'), fn ($q) => $q->where('number', 'like', "%{$request->search}%"))
            ->when($request->filled('supplier'), fn ($q) => $q->where('supplier_id', $request->supplier))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->latest('issue_date')
            ->paginate(20)
            ->withQueryString();

        $suppliers = Supplier::query()->orderBy('company_name')->get();

        return view('suppliers.purchase_quotes.index', compact('quotes', 'suppliers'));
    }

    public function create(): View
    {
        $suppliers = Supplier::query()->orderBy('company_name')->get();
        $products = Product::query()->where('is_active', true)->orderBy('name')->get();

        return view('suppliers.purchase_quotes.create', compact('suppliers', 'products'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')],
            'issue_date' => ['required', 'date'],
            'valid_until' => ['nullable', 'date'],
            'status' => ['required', Rule::in(PurchaseQuote::statusOptions())],
            'currency' => ['nullable', 'string', 'max:8'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1'],
        ]);

        $quote = PurchaseQuote::create([
            'number' => next_document_number('purchase_quote', 'PQ'),
            'supplier_id' => $data['supplier_id'],
            'issue_date' => $data['issue_date'],
            'valid_until' => $data['valid_until'] ?? null,
            'status' => $data['status'],
            'currency' => $data['currency'] ?? null,
            'exchange_rate' => exchange_rate_for($data['currency'] ?? null),
            'notes' => $data['notes'] ?? null,
        ]);

        $totals = DocumentItems::sync($quote, $request->input('items', []));
        $quote->update(['subtotal' => $totals['subtotal'], 'tax_amount' => $totals['tax'], 'total' => $totals['total']]);

        return redirect()->route('suppliers.purchase_quotes.edit', $quote)
            ->with('toasts', [['type' => 'success', 'message' => "Purchase quote {$quote->number} created."]]);
    }

    public function edit(PurchaseQuote $quote): View
    {
        $quote->load(['supplier', 'items.product', 'statusEvents.user']);
        $suppliers = Supplier::query()->orderBy('company_name')->get();
        $products = Product::query()->where('is_active', true)->orderBy('name')->get();

        return view('suppliers.purchase_quotes.edit', compact('quote', 'suppliers', 'products'));
    }

    public function show(PurchaseQuote $quote): View
    {
        $quote->load(['supplier', 'items.product']);

        return view('documents.show', DocumentData::build($quote));
    }

    public function pdf(PurchaseQuote $quote): StreamedResponse
    {
        $quote->load(['supplier', 'items.product']);

        $html = view('pdf.document', DocumentData::build($quote))->render();

        $pdf = Pdf::loadHTML($html)->setPaper('a4', 'portrait');

        return response()->streamDownload(function () use ($pdf) {
            echo $pdf->output();
        }, 'purchase-quote-'.$quote->number.'.pdf', [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="purchase-quote-'.$quote->number.'.pdf"',
        ]);
    }

    public function update(Request $request, PurchaseQuote $quote): RedirectResponse
    {
        if ($quote->isConverted()) {
            return back()->with('toasts', [['type' => 'danger', 'message' => 'Converted quotes cannot be edited.']]);
        }

        $data = $request->validate([
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')],
            'issue_date' => ['required', 'date'],
            'valid_until' => ['nullable', 'date'],
            'status' => ['required', Rule::in(PurchaseQuote::statusOptions())],
            'currency' => ['nullable', 'string', 'max:8'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1'],
        ]);

        $quote->update([
            'supplier_id' => $data['supplier_id'],
            'issue_date' => $data['issue_date'],
            'valid_until' => $data['valid_until'] ?? null,
            'status' => $data['status'],
            'currency' => $data['currency'] ?? null,
            'exchange_rate' => exchange_rate_for($data['currency'] ?? null),
            'notes' => $data['notes'] ?? null,
        ]);

        $totals = DocumentItems::sync($quote, $request->input('items', []));
        $quote->update(['subtotal' => $totals['subtotal'], 'tax_amount' => $totals['tax'], 'total' => $totals['total']]);

        return back()->with('toasts', [['type' => 'success', 'message' => "Purchase quote {$quote->number} updated."]]);
    }

    public function destroy(PurchaseQuote $quote): RedirectResponse
    {
        $number = $quote->number;
        $quote->delete();

        return redirect()->route('suppliers.purchase_quotes.index')
            ->with('toasts', [['type' => 'success', 'message' => "Purchase quote {$number} deleted."]]);
    }

    public function export(Request $request): StreamedResponse
    {
        $quotes = PurchaseQuote::query()
            ->with(['supplier'])
            ->when($request->filled('search'), fn ($q) => $q->where('number', 'like', "%{$request->search}%"))
            ->when($request->filled('supplier'), fn ($q) => $q->where('supplier_id', $request->supplier))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->latest('issue_date')
            ->get();

        return $this->streamCsv('purchase-quotes-'.now()->format('Y-m-d').'.csv', ['Number', 'Supplier', 'Issue date', 'Valid until', 'Status', 'Subtotal', 'Tax', 'Total'], $quotes->map(fn (PurchaseQuote $q) => [
            $q->number,
            $q->supplier?->company_name,
            $q->issue_date?->format('Y-m-d'),
            $q->valid_until?->format('Y-m-d'),
            ucfirst($q->status),
            $q->subtotal,
            $q->tax_amount,
            $q->total,
        ]));
    }

    public function convert(PurchaseQuote $quote): RedirectResponse
    {
        if ($quote->isConverted()) {
            return back()->with('toasts', [['type' => 'danger', 'message' => 'Purchase quote already converted.']]);
        }

        $order = PurchaseOrder::create([
            'number' => next_document_number('purchase_order', 'POR'),
            'quote_id' => $quote->id,
            'supplier_id' => $quote->supplier_id,
            'order_date' => now()->toDateString(),
            'status' => 'draft',
            'currency' => $quote->currency,
            'exchange_rate' => $quote->exchange_rate ?? exchange_rate_for($quote->currency),
            'subtotal' => $quote->subtotal,
            'discount_amount' => 0,
            'tax_amount' => $quote->tax_amount,
            'total' => $quote->total,
            'notes' => 'Converted from purchase quote '.$quote->number,
        ]);

        $order->items()->createMany($quote->items()->get(['product_id', 'description', 'qty', 'unit_price', 'discount_percent', 'tax_percent', 'line_total'])->toArray());

        $quote->update(['converted_to_order_id' => $order->id, 'status' => 'converted']);

        return redirect()->route('suppliers.purchase_orders.edit', $order)
            ->with('toasts', [['type' => 'success', 'message' => "Purchase quote {$quote->number} converted to order {$order->number}."]]);
    }

    public function updateStatus(Request $request, PurchaseQuote $quote): RedirectResponse
    {
        if ($quote->isConverted()) {
            return back()->with('toasts', [['type' => 'danger', 'message' => 'Converted quotes cannot change status.']]);
        }

        $data = $request->validate([
            'status' => ['required', Rule::in(PurchaseQuote::statusOptions())],
        ]);

        $quote->update(['status' => $data['status']]);

        return back()->with('toasts', [['type' => 'success', 'message' => "Purchase quote {$quote->number} marked as {$data['status']}."]]);
    }
}
