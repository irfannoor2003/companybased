<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\SalesCustomer;
use App\Models\SalesOrder;
use App\Models\SalesQuote;
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

class QuoteController extends Controller
{
    use ExportsCsv;
    use DiscountLimit;

    public function index(Request $request): View
    {
        $quotes = SalesQuote::query()
            ->with(['customer'])
            ->when($request->filled('search'), fn ($q) => $q->where('number', 'like', "%{$request->search}%"))
            ->when($request->filled('customer'), fn ($q) => $q->where('customer_id', $request->customer))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->latest('issue_date')
            ->paginate(20)
            ->withQueryString();

        $customers = SalesCustomer::query()->orderBy('company_name')->get();

        return view('sales.quotes.index', compact('quotes', 'customers'));
    }

    public function create(): View
    {
        $customers = SalesCustomer::query()->orderBy('company_name')->get();
        $priceLists = PriceList::query()->orderBy('name')->get();
        $products = Product::query()->where('is_active', true)->orderBy('name')->get();
        $maxDiscount = $this->getMaxDiscountForUser();

        return view('sales.quotes.create', compact('customers', 'priceLists', 'products', 'maxDiscount'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer', Rule::exists('sales_customers', 'id')],
            'price_list_id' => ['nullable', 'integer', Rule::exists('price_lists', 'id')],
            'issue_date' => ['required', 'date'],
            'valid_until' => ['nullable', 'date'],
            'status' => ['required', Rule::in(SalesQuote::statusOptions())],
            'currency' => ['nullable', 'string', 'max:8'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1'],
        ]);

        $this->validateDiscountLimits($request->input('items', []));

        $quote = SalesQuote::create([
            'number' => next_document_number('quote', 'Q'),
            'customer_id' => $data['customer_id'],
            'price_list_id' => $data['price_list_id'] ?? null,
            'issue_date' => $data['issue_date'],
            'valid_until' => $data['valid_until'] ?? null,
            'status' => $data['status'],
            'currency' => $data['currency'] ?? null,
            'exchange_rate' => exchange_rate_for($data['currency'] ?? null),
            'notes' => $data['notes'] ?? null,
        ]);

        $totals = DocumentItems::sync($quote, $request->input('items', []));
        $quote->update(['subtotal' => $totals['subtotal'], 'tax_amount' => $totals['tax'], 'total' => $totals['total']]);

        return redirect()->route('sales.quotes.index')
            ->with('toasts', [['type' => 'success', 'message' => "Quote {$quote->number} created."]]);
    }

    public function edit(SalesQuote $quote): View
    {
        $quote->load(['customer', 'items.product']);
        $customers = SalesCustomer::query()->orderBy('company_name')->get();
        $priceLists = PriceList::query()->orderBy('name')->get();
        $products = Product::query()->where('is_active', true)->orderBy('name')->get();
        $maxDiscount = $this->getMaxDiscountForUser();

        return view('sales.quotes.edit', compact('quote', 'customers', 'priceLists', 'products', 'maxDiscount'));
    }

    public function show(SalesQuote $quote): View
    {
        $quote->load(['customer', 'items.product']);

        return view('documents.show', DocumentData::build($quote));
    }

    public function pdf(SalesQuote $quote): StreamedResponse
    {
        $quote->load(['customer', 'items.product']);

        $html = view('pdf.document', DocumentData::build($quote))->render();

        $pdf = Pdf::loadHTML($html)->setPaper('a4', 'portrait');

        return $pdf->stream('quote-'.$quote->number.'.pdf');
    }

    public function update(Request $request, SalesQuote $quote): RedirectResponse
    {
        if ($quote->isConverted()) {
            return back()->with('toasts', [['type' => 'danger', 'message' => 'Converted quotes cannot be edited.']]);
        }

        $data = $request->validate([
            'customer_id' => ['required', 'integer', Rule::exists('sales_customers', 'id')],
            'price_list_id' => ['nullable', 'integer', Rule::exists('price_lists', 'id')],
            'issue_date' => ['required', 'date'],
            'valid_until' => ['nullable', 'date'],
            'status' => ['required', Rule::in(SalesQuote::statusOptions())],
            'currency' => ['nullable', 'string', 'max:8'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1'],
        ]);

        $this->validateDiscountLimits($request->input('items', []));

        $quote->update([
            'customer_id' => $data['customer_id'],
            'price_list_id' => $data['price_list_id'] ?? null,
            'issue_date' => $data['issue_date'],
            'valid_until' => $data['valid_until'] ?? null,
            'status' => $data['status'],
            'currency' => $data['currency'] ?? null,
            'exchange_rate' => exchange_rate_for($data['currency'] ?? null),
            'notes' => $data['notes'] ?? null,
        ]);

        $totals = DocumentItems::sync($quote, $request->input('items', []));
        $quote->update(['subtotal' => $totals['subtotal'], 'tax_amount' => $totals['tax'], 'total' => $totals['total']]);

        return back()->with('toasts', [['type' => 'success', 'message' => "Quote {$quote->number} updated."]]);
    }

    public function destroy(SalesQuote $quote): RedirectResponse
    {
        $number = $quote->number;
        $quote->delete();

        return redirect()->route('sales.quotes.index')
            ->with('toasts', [['type' => 'success', 'message' => "Quote {$number} deleted."]]);
    }

    public function export(Request $request): StreamedResponse
    {
        $quotes = SalesQuote::query()
            ->with(['customer'])
            ->when($request->filled('search'), fn ($q) => $q->where('number', 'like', "%{$request->search}%"))
            ->when($request->filled('customer'), fn ($q) => $q->where('customer_id', $request->customer))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->latest('issue_date')
            ->get();

        return $this->streamCsv('quotes-'.now()->format('Y-m-d').'.csv', ['Number', 'Customer', 'Issue date', 'Valid until', 'Status', 'Subtotal', 'Tax', 'Total'], $quotes->map(fn (SalesQuote $q) => [
            $q->number,
            $q->customer?->company_name,
            $q->issue_date?->format('Y-m-d'),
            $q->valid_until?->format('Y-m-d'),
            ucfirst($q->status),
            $q->subtotal,
            $q->tax_amount,
            $q->total,
        ]));
    }

    public function convert(SalesQuote $quote): RedirectResponse
    {
        if ($quote->isConverted()) {
            return back()->with('toasts', [['type' => 'danger', 'message' => 'Quote already converted.']]);
        }

        $order = SalesOrder::create([
            'number' => next_document_number('order', 'SO'),
            'quote_id' => $quote->id,
            'customer_id' => $quote->customer_id,
            'salesman_id' => auth()->id(),
            'issue_date' => now()->toDateString(),
            'status' => 'draft',
            'currency' => $quote->currency,
            'exchange_rate' => $quote->exchange_rate ?? exchange_rate_for($quote->currency),
            'subtotal' => $quote->subtotal,
            'discount_amount' => 0,
            'tax_amount' => $quote->tax_amount,
            'total' => $quote->total,
            'shipping_address' => $quote->customer->address,
            'notes' => 'Converted from quote '.$quote->number,
        ]);

        $order->items()->createMany($quote->items()->get(['product_id', 'description', 'qty', 'unit_price', 'discount_percent', 'tax_percent', 'line_total'])->toArray());

        $quote->update(['converted_to_order_id' => $order->id, 'status' => 'converted']);

        return redirect()->route('sales.orders.index')
            ->with('toasts', [['type' => 'success', 'message' => "Quote {$quote->number} converted to order {$order->number}."]]);
    }

    public function updateStatus(Request $request, SalesQuote $quote): RedirectResponse
    {
        if ($quote->isConverted()) {
            return back()->with('toasts', [['type' => 'danger', 'message' => 'Converted quotes cannot change status.']]);
        }

        $data = $request->validate([
            'status' => ['required', Rule::in(SalesQuote::statusOptions())],
        ]);

        $quote->update(['status' => $data['status']]);

        return back()->with('toasts', [['type' => 'success', 'message' => "Quote {$quote->number} marked as {$data['status']}."]]);
    }
}
