<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\DebitNote;
use App\Models\Product;
use App\Models\PurchaseInvoice;
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

class DebitNoteController extends Controller
{
    use ExportsCsv;

    public function index(Request $request): View
    {
        $debitNotes = DebitNote::query()
            ->with(['supplier'])
            ->when($request->filled('search'), fn ($q) => $q->where('number', 'like', "%{$request->search}%"))
            ->when($request->filled('supplier'), fn ($q) => $q->where('supplier_id', $request->supplier))
            ->latest('issue_date')
            ->paginate(20)
            ->withQueryString();

        $suppliers = Supplier::query()->orderBy('company_name')->get();

        return view('suppliers.debit_notes.index', compact('debitNotes', 'suppliers'));
    }

    public function create(Request $request): View
    {
        $suppliers = Supplier::query()->orderBy('company_name')->get();
        $products = Product::query()->where('is_active', true)->orderBy('name')->get();

        $fromInvoice = null;
        if ($request->filled('invoice')) {
            $fromInvoice = PurchaseInvoice::query()->with(['items.product'])->findOrFail($request->invoice);
        }

        return view('suppliers.debit_notes.create', compact('suppliers', 'products', 'fromInvoice'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

        $note = DebitNote::create([
            'number' => next_document_number('debit_note', 'DBN'),
            'invoice_id' => $data['invoice_id'] ?? null,
            'supplier_id' => $data['supplier_id'],
            'issue_date' => $data['issue_date'],
            'reason' => $data['reason'] ?? null,
            'currency' => $data['currency'] ?? null,
            'exchange_rate' => exchange_rate_for($data['currency'] ?? null),
            'notes' => $data['notes'] ?? null,
        ]);

        $totals = DocumentItems::sync($note, $request->input('items', []));
        $note->update(['subtotal' => $totals['subtotal'], 'tax_amount' => $totals['tax'], 'total' => $totals['total']]);

        return redirect()->route('suppliers.debit_notes.index')
            ->with('toasts', [['type' => 'success', 'message' => "Debit note {$note->number} created."]]);
    }

    public function edit(DebitNote $debitNote): View
    {
        $debitNote->load(['supplier', 'items.product', 'invoice']);
        $suppliers = Supplier::query()->orderBy('company_name')->get();
        $products = Product::query()->where('is_active', true)->orderBy('name')->get();

        return view('suppliers.debit_notes.edit', compact('debitNote', 'suppliers', 'products'));
    }

    public function show(DebitNote $debitNote): View
    {
        $debitNote->load(['supplier', 'items.product', 'invoice']);

        return view('documents.show', DocumentData::build($debitNote));
    }

    public function pdf(DebitNote $debitNote): \Illuminate\Http\Response
    {
        $debitNote->load(['supplier', 'items.product', 'invoice']);

        $html = view('pdf.document', DocumentData::build($debitNote))->render();

        $pdf = Pdf::loadHTML($html)->setPaper('a4', 'portrait');

        return $pdf->stream('debit-note-'.$debitNote->number.'.pdf');
    }

    public function update(Request $request, DebitNote $debitNote): RedirectResponse
    {
        $data = $this->validateData($request);

        $debitNote->update([
            'invoice_id' => $data['invoice_id'] ?? null,
            'supplier_id' => $data['supplier_id'],
            'issue_date' => $data['issue_date'],
            'reason' => $data['reason'] ?? null,
            'currency' => $data['currency'] ?? null,
            'exchange_rate' => exchange_rate_for($data['currency'] ?? null),
            'notes' => $data['notes'] ?? null,
        ]);

        $totals = DocumentItems::sync($debitNote, $request->input('items', []));
        $debitNote->update(['subtotal' => $totals['subtotal'], 'tax_amount' => $totals['tax'], 'total' => $totals['total']]);

        return back()->with('toasts', [['type' => 'success', 'message' => "Debit note {$debitNote->number} updated."]]);
    }

    public function destroy(DebitNote $debitNote): RedirectResponse
    {
        $number = $debitNote->number;
        $debitNote->delete();

        return redirect()->route('suppliers.debit_notes.index')
            ->with('toasts', [['type' => 'success', 'message' => "Debit note {$number} deleted."]]);
    }

    public function export(Request $request): StreamedResponse
    {
        $debitNotes = DebitNote::query()
            ->with(['supplier'])
            ->when($request->filled('search'), fn ($q) => $q->where('number', 'like', "%{$request->search}%"))
            ->when($request->filled('supplier'), fn ($q) => $q->where('supplier_id', $request->supplier))
            ->latest('issue_date')
            ->get();

        return $this->streamCsv('debit-notes-'.now()->format('Y-m-d').'.csv', ['Number', 'Supplier', 'Invoice', 'Issue date', 'Reason', 'Subtotal', 'Tax', 'Total', 'Applied'], $debitNotes->map(fn (DebitNote $n) => [
            $n->number,
            $n->supplier?->company_name,
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
            'invoice_id' => ['nullable', 'integer', Rule::exists('purchase_invoices', 'id')],
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')],
            'issue_date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
            'currency' => ['nullable', 'string', 'max:8'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1'],
        ]);
    }
}
