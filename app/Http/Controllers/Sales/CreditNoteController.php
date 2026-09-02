<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\SalesCreditNote;
use App\Models\SalesCustomer;
use App\Models\SalesInvoice;
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

class CreditNoteController extends Controller
{
    use ExportsCsv;
    use DiscountLimit;

    public function index(Request $request): View
    {
        $creditNotes = SalesCreditNote::query()
            ->with(['customer'])
            ->when($request->filled('search'), fn ($q) => $q->where('number', 'like', "%{$request->search}%"))
            ->when($request->filled('customer'), fn ($q) => $q->where('customer_id', $request->customer))
            ->latest('issue_date')
            ->paginate(20)
            ->withQueryString();

        $customers = SalesCustomer::query()->orderBy('company_name')->get();

        return view('sales.credit_notes.index', compact('creditNotes', 'customers'));
    }

    public function create(Request $request): View
    {
        $customers = SalesCustomer::query()->orderBy('company_name')->get();
        $products = Product::query()->where('is_active', true)->orderBy('name')->get();
        $maxDiscount = $this->getMaxDiscountForUser();

        $fromInvoice = null;
        if ($request->filled('invoice')) {
            $fromInvoice = SalesInvoice::query()->with(['items.product'])->findOrFail($request->invoice);
        }

        return view('sales.credit_notes.create', compact('customers', 'products', 'fromInvoice', 'maxDiscount'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);
        $this->validateDiscountLimits($request->input('items', []));

        $note = SalesCreditNote::create([
            'number' => next_document_number('credit_note', 'CN'),
            'invoice_id' => $data['invoice_id'] ?? null,
            'customer_id' => $data['customer_id'],
            'issue_date' => $data['issue_date'],
            'reason' => $data['reason'] ?? null,
            'currency' => $data['currency'] ?? null,
            'exchange_rate' => exchange_rate_for($data['currency'] ?? null),
            'notes' => $data['notes'] ?? null,
        ]);

        $totals = DocumentItems::sync($note, $request->input('items', []));
        $note->update(['subtotal' => $totals['subtotal'], 'tax_amount' => $totals['tax'], 'total' => $totals['total']]);

        return redirect()->route('sales.credit_notes.index')
            ->with('toasts', [['type' => 'success', 'message' => "Credit note {$note->number} created."]]);
    }

    public function edit(SalesCreditNote $creditNote): View
    {
        $creditNote->load(['customer', 'items.product', 'invoice']);
        $customers = SalesCustomer::query()->orderBy('company_name')->get();
        $products = Product::query()->where('is_active', true)->orderBy('name')->get();
        $maxDiscount = $this->getMaxDiscountForUser();

        return view('sales.credit_notes.edit', compact('creditNote', 'customers', 'products', 'maxDiscount'));
    }

    public function show(SalesCreditNote $creditNote): View
    {
        $creditNote->load(['customer', 'items.product', 'invoice']);

        return view('documents.show', DocumentData::build($creditNote));
    }

    public function pdf(SalesCreditNote $creditNote): \Illuminate\Http\Response
    {
        $creditNote->load(['customer', 'items.product', 'invoice']);

        $html = view('pdf.document', DocumentData::build($creditNote))->render();

        $pdf = Pdf::loadHTML($html)->setPaper('a4', 'portrait');

        return $pdf->stream('credit-note-'.$creditNote->number.'.pdf');
    }

    public function update(Request $request, SalesCreditNote $creditNote): RedirectResponse
    {
        $data = $this->validateData($request);
        $this->validateDiscountLimits($request->input('items', []));

        $creditNote->update([
            'invoice_id' => $data['invoice_id'] ?? null,
            'customer_id' => $data['customer_id'],
            'issue_date' => $data['issue_date'],
            'reason' => $data['reason'] ?? null,
            'currency' => $data['currency'] ?? null,
            'exchange_rate' => exchange_rate_for($data['currency'] ?? null),
            'notes' => $data['notes'] ?? null,
        ]);

        $totals = DocumentItems::sync($creditNote, $request->input('items', []));
        $creditNote->update(['subtotal' => $totals['subtotal'], 'tax_amount' => $totals['tax'], 'total' => $totals['total']]);

        return back()->with('toasts', [['type' => 'success', 'message' => "Credit note {$creditNote->number} updated."]]);
    }

    public function destroy(SalesCreditNote $creditNote): RedirectResponse
    {
        $number = $creditNote->number;
        $creditNote->delete();

        return redirect()->route('sales.credit_notes.index')
            ->with('toasts', [['type' => 'success', 'message' => "Credit note {$number} deleted."]]);
    }

    public function export(Request $request): StreamedResponse
    {
        $creditNotes = SalesCreditNote::query()
            ->with(['customer'])
            ->when($request->filled('search'), fn ($q) => $q->where('number', 'like', "%{$request->search}%"))
            ->when($request->filled('customer'), fn ($q) => $q->where('customer_id', $request->customer))
            ->latest('issue_date')
            ->get();

        return $this->streamCsv('credit-notes-'.now()->format('Y-m-d').'.csv', ['Number', 'Customer', 'Invoice', 'Issue date', 'Reason', 'Subtotal', 'Tax', 'Total', 'Applied'], $creditNotes->map(fn (SalesCreditNote $n) => [
            $n->number,
            $n->customer?->company_name,
            $n->invoice?->number ?? '—',
            $n->issue_date?->format('Y-m-d'),
            $n->reason,
            $n->subtotal,
            $n->tax_amount,
            $n->total,
            $n->applied_amount,
        ]));
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'invoice_id' => ['nullable', 'integer', Rule::exists('sales_invoices', 'id')],
            'customer_id' => ['required', 'integer', Rule::exists('sales_customers', 'id')],
            'issue_date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
            'currency' => ['nullable', 'string', 'max:8'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1'],
        ]);
    }
}
