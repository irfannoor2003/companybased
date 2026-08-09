<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\SalesCustomer;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Models\SalesPayment;
use App\Models\SalesStatusEvent;
use App\Support\DocumentItems;
use App\Support\ExportsCsv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvoiceController extends Controller
{
    use ExportsCsv;

    public function index(Request $request): View
    {
        $invoices = SalesInvoice::query()
            ->with(['customer'])
            ->when($request->filled('search'), fn ($q) => $q->where('number', 'like', "%{$request->search}%"))
            ->when($request->filled('customer'), fn ($q) => $q->where('customer_id', $request->customer))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->latest('issue_date')
            ->paginate(20)
            ->withQueryString();

        $customers = SalesCustomer::query()->orderBy('company_name')->get();

        return view('sales.invoices.index', compact('invoices', 'customers'));
    }

    public function create(Request $request): View
    {
        $customers = SalesCustomer::query()->orderBy('company_name')->get();
        $products = \App\Models\Product::query()->where('is_active', true)->orderBy('name')->get();

        $fromOrder = null;
        if ($request->filled('order')) {
            $fromOrder = SalesOrder::query()->with(['items'])->findOrFail($request->order);
        }

        return view('sales.invoices.create', compact('customers', 'products', 'fromOrder'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

        $invoice = SalesInvoice::create([
            'number' => next_document_number('invoice', 'INV'),
            'order_id' => $data['order_id'] ?? null,
            'customer_id' => $data['customer_id'],
            'issue_date' => $data['issue_date'],
            'due_date' => $data['due_date'] ?? null,
            'status' => $data['status'],
            'currency' => $data['currency'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        $totals = DocumentItems::sync($invoice, $request->input('items', []));
        $invoice->update(['subtotal' => $totals['subtotal'], 'tax_amount' => $totals['tax'], 'total' => $totals['total']]);

        $this->recalculateStatus($invoice);

        return redirect()->route('sales.invoices.edit', $invoice)
            ->with('toasts', [['type' => 'success', 'message' => "Invoice {$invoice->number} created."]]);
    }

    public function edit(SalesInvoice $invoice): View
    {
        $invoice->load(['customer', 'items.product', 'payments', 'statusEvents.user', 'creditNotes']);
        $customers = SalesCustomer::query()->orderBy('company_name')->get();
        $products = \App\Models\Product::query()->where('is_active', true)->orderBy('name')->get();

        return view('sales.invoices.edit', compact('invoice', 'customers', 'products'));
    }

    public function update(Request $request, SalesInvoice $invoice): RedirectResponse
    {
        $data = $this->validateData($request);

        $invoice->update([
            'order_id' => $data['order_id'] ?? null,
            'customer_id' => $data['customer_id'],
            'issue_date' => $data['issue_date'],
            'due_date' => $data['due_date'] ?? null,
            'status' => $data['status'],
            'currency' => $data['currency'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        $totals = DocumentItems::sync($invoice, $request->input('items', []));
        $invoice->update(['subtotal' => $totals['subtotal'], 'tax_amount' => $totals['tax'], 'total' => $totals['total']]);

        $this->recalculateStatus($invoice);

        return back()->with('toasts', [['type' => 'success', 'message' => "Invoice {$invoice->number} updated."]]);
    }

    public function destroy(SalesInvoice $invoice): RedirectResponse
    {
        $number = $invoice->number;
        $invoice->delete();

        return redirect()->route('sales.invoices.index')
            ->with('toasts', [['type' => 'success', 'message' => "Invoice {$number} deleted."]]);
    }

    public function export(Request $request): StreamedResponse
    {
        $invoices = SalesInvoice::query()
            ->with(['customer'])
            ->when($request->filled('search'), fn ($q) => $q->where('number', 'like', "%{$request->search}%"))
            ->when($request->filled('customer'), fn ($q) => $q->where('customer_id', $request->customer))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->latest('issue_date')
            ->get();

        return $this->streamCsv('invoices-'.now()->format('Y-m-d').'.csv', ['Number', 'Customer', 'Issue date', 'Due date', 'Status', 'Subtotal', 'Tax', 'Total', 'Paid', 'Balance'], $invoices->map(fn (SalesInvoice $i) => [
            $i->number,
            $i->customer?->company_name,
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

    public function recordPayment(Request $request, SalesInvoice $invoice): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_date' => ['required', 'date'],
            'method' => ['required', Rule::in(SalesPayment::methodOptions())],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $remaining = $invoice->balance();

        if ((float) $data['amount'] > $remaining) {
            return back()->withInput()
                ->with('toasts', [['type' => 'danger', 'message' => 'Payment exceeds the outstanding balance of '.money($remaining, $invoice->currency).'.']]);
        }

        SalesPayment::create([
            'invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'amount' => $data['amount'],
            'payment_date' => $data['payment_date'],
            'method' => $data['method'],
            'reference' => $data['reference'] ?? null,
            'currency' => $invoice->currency,
            'notes' => $data['notes'] ?? null,
        ]);

        $invoice->update(['paid_amount' => round((float) $invoice->paid_amount + (float) $data['amount'], 2)]);
        $this->recalculateStatus($invoice);

        return back()->with('toasts', [['type' => 'success', 'message' => 'Payment of '.money($data['amount'], $invoice->currency).' recorded.']]);
    }

    private function recalculateStatus(SalesInvoice $invoice): void
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

        SalesStatusEvent::create([
            'trackable_type' => SalesInvoice::class,
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
            'order_id' => ['nullable', 'integer', Rule::exists('sales_orders', 'id')],
            'customer_id' => ['required', 'integer', Rule::exists('sales_customers', 'id')],
            'issue_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date'],
            'status' => ['required', Rule::in(SalesInvoice::statusOptions())],
            'currency' => ['nullable', 'string', 'max:8'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1'],
        ]);
    }
}
