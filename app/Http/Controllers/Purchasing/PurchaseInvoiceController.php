<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseStatusEvent;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Support\DocumentItems;
use App\Support\ExportsCsv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Barryvdh\DomPDF\Facade\Pdf;

class PurchaseInvoiceController extends Controller
{
    use ExportsCsv;

    public function index(Request $request): View
    {
        $invoices = PurchaseInvoice::query()
            ->with(['supplier'])
            ->when($request->filled('search'), fn ($q) => $q->where('number', 'like', "%{$request->search}%"))
            ->when($request->filled('supplier'), fn ($q) => $q->where('supplier_id', $request->supplier))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->latest('issue_date')
            ->paginate(20)
            ->withQueryString();

        $suppliers = Supplier::query()->orderBy('company_name')->get();

        return view('suppliers.purchase_invoices.index', compact('invoices', 'suppliers'));
    }

    public function create(Request $request): View
    {
        $suppliers = Supplier::query()->orderBy('company_name')->get();
        $products = Product::query()->where('is_active', true)->orderBy('name')->get();

        $fromOrder = null;
        if ($request->filled('order')) {
            $fromOrder = PurchaseOrder::query()->with(['items'])->findOrFail($request->order);
        }

        return view('suppliers.purchase_invoices.create', compact('suppliers', 'products', 'fromOrder'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

        $invoice = PurchaseInvoice::create([
            'number' => next_document_number('purchase_invoice', 'PIN'),
            'order_id' => $data['order_id'] ?? null,
            'supplier_id' => $data['supplier_id'],
            'issue_date' => $data['issue_date'],
            'due_date' => $data['due_date'] ?? null,
            'status' => $data['status'],
            'currency' => $data['currency'] ?? null,
            'exchange_rate' => exchange_rate_for($data['currency'] ?? null),
            'notes' => $data['notes'] ?? null,
        ]);

        $totals = DocumentItems::sync($invoice, $request->input('items', []));
        $invoice->update(['subtotal' => $totals['subtotal'], 'tax_amount' => $totals['tax'], 'total' => $totals['total']]);

        $this->recalculateStatus($invoice);

        return redirect()->route('suppliers.purchase_invoices.edit', $invoice)
            ->with('toasts', [['type' => 'success', 'message' => "Purchase invoice {$invoice->number} created."]]);
    }

    public function edit(PurchaseInvoice $invoice): View
    {
        $invoice->load(['supplier', 'items.product', 'payments', 'statusEvents.user', 'debitNotes']);
        $suppliers = Supplier::query()->orderBy('company_name')->get();
        $products = Product::query()->where('is_active', true)->orderBy('name')->get();

        return view('suppliers.purchase_invoices.edit', compact('invoice', 'suppliers', 'products'));
    }

    public function show(PurchaseInvoice $invoice): View
    {
        $invoice->load(['supplier', 'items.product', 'payments', 'statusEvents.user', 'debitNotes']);

        return view('suppliers.purchase_invoices.show', compact('invoice'));
    }

    public function pdf(PurchaseInvoice $invoice): StreamedResponse
    {
        $invoice->load(['supplier', 'items.product', 'payments', 'debitNotes']);

        $html = view('suppliers.purchase_invoices.pdf', compact('invoice'))->render();

        $pdf = Pdf::loadHTML($html)->setPaper('a4', 'portrait');

        return response()->streamDownload(function () use ($pdf) {
            echo $pdf->output();
        }, 'purchase-invoice-'.$invoice->number.'.pdf', [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="purchase-invoice-'.$invoice->number.'.pdf"',
        ]);
    }

    public function update(Request $request, PurchaseInvoice $invoice): RedirectResponse
    {
        $data = $this->validateData($request);

        $invoice->update([
            'order_id' => $data['order_id'] ?? null,
            'supplier_id' => $data['supplier_id'],
            'issue_date' => $data['issue_date'],
            'due_date' => $data['due_date'] ?? null,
            'status' => $data['status'],
            'currency' => $data['currency'] ?? null,
            'exchange_rate' => exchange_rate_for($data['currency'] ?? null),
            'notes' => $data['notes'] ?? null,
        ]);

        $totals = DocumentItems::sync($invoice, $request->input('items', []));
        $invoice->update(['subtotal' => $totals['subtotal'], 'tax_amount' => $totals['tax'], 'total' => $totals['total']]);

        $this->recalculateStatus($invoice);

        return back()->with('toasts', [['type' => 'success', 'message' => "Purchase invoice {$invoice->number} updated."]]);
    }

    public function destroy(PurchaseInvoice $invoice): RedirectResponse
    {
        $number = $invoice->number;
        $invoice->delete();

        return redirect()->route('suppliers.purchase_invoices.index')
            ->with('toasts', [['type' => 'success', 'message' => "Purchase invoice {$number} deleted."]]);
    }

    public function export(Request $request): StreamedResponse
    {
        $invoices = PurchaseInvoice::query()
            ->with(['supplier'])
            ->when($request->filled('search'), fn ($q) => $q->where('number', 'like', "%{$request->search}%"))
            ->when($request->filled('supplier'), fn ($q) => $q->where('supplier_id', $request->supplier))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->latest('issue_date')
            ->get();

        return $this->streamCsv('purchase-invoices-'.now()->format('Y-m-d').'.csv', ['Number', 'Supplier', 'Issue date', 'Due date', 'Status', 'Subtotal', 'Tax', 'Total', 'Paid', 'Balance'], $invoices->map(fn (PurchaseInvoice $i) => [
            $i->number,
            $i->supplier?->company_name,
            $i->issue_date?->format('Y-m-d'),
            $i->due_date?->format('Y-m-d'),
            ucfirst($i->status),
            $i->subtotal,
            $i->tax_amount,
            $i->total,
            $i->paid_amount,
            $i->balance(),
        ]));
    }

    public function recordPayment(Request $request, PurchaseInvoice $invoice): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_date' => ['required', 'date'],
            'method' => ['required', Rule::in(SupplierPayment::methodOptions())],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $remaining = $invoice->balance();

        if ((float) $data['amount'] > $remaining) {
            return back()->withInput()
                ->with('toasts', [['type' => 'danger', 'message' => 'Payment exceeds the outstanding balance of '.money($remaining, $invoice->currency).'.']]);
        }

        SupplierPayment::create([
            'number' => next_document_number('supplier_payment', 'SP'),
            'invoice_id' => $invoice->id,
            'supplier_id' => $invoice->supplier_id,
            'amount' => $data['amount'],
            'payment_date' => $data['payment_date'],
            'method' => $data['method'],
            'reference' => $data['reference'] ?? null,
            'currency' => $invoice->currency,
            'exchange_rate' => $invoice->exchange_rate ?? exchange_rate_for($invoice->currency),
            'notes' => $data['notes'] ?? null,
        ]);

        $invoice->update(['paid_amount' => round((float) $invoice->paid_amount + (float) $data['amount'], 2)]);
        $this->recalculateStatus($invoice);

        return back()->with('toasts', [['type' => 'success', 'message' => 'Payment of '.money($data['amount'], $invoice->currency).' recorded.']]);
    }

    private function recalculateStatus(PurchaseInvoice $invoice): void
    {
        if ($invoice->status === 'cancelled') {
            return;
        }

        $newStatus = $invoice->isPaid()
            ? 'paid'
            : ((float) $invoice->paid_amount > 0 ? 'partially_paid' : $invoice->status);

        if ($newStatus === $invoice->status) {
            return;
        }

        PurchaseStatusEvent::create([
            'trackable_type' => PurchaseInvoice::class,
            'trackable_id' => $invoice->id,
            'from_status' => $invoice->status,
            'to_status' => $newStatus,
            'user_id' => auth()->id(),
            'note' => 'Automatic status update',
        ]);

        $invoice->update(['status' => $newStatus]);
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'order_id' => ['nullable', 'integer', Rule::exists('purchase_orders', 'id')],
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')],
            'issue_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date'],
            'status' => ['required', Rule::in(PurchaseInvoice::statusOptions())],
            'currency' => ['nullable', 'string', 'max:8'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1'],
        ]);
    }
}
