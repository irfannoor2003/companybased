<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\Product;
use App\Models\SalesCustomer;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Models\SalesPayment;
use App\Models\SalesStatusEvent;
use App\Services\NotificationService;
use App\Support\DiscountLimit;
use App\Support\DocumentItems;
use App\Support\ExportsCsv;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvoiceController extends Controller
{
    use DiscountLimit;
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
        $products = Product::query()->where('is_active', true)->orderBy('name')->get();
        $maxDiscount = $this->getMaxDiscountForUser();

        $fromOrder = null;
        if ($request->filled('order')) {
            $fromOrder = SalesOrder::query()->with(['items'])->findOrFail($request->order);
        }

        return view('sales.invoices.create', compact('customers', 'products', 'fromOrder', 'maxDiscount'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);
        $this->validateDiscountLimits($request->input('items', []));

        $invoice = SalesInvoice::create([
            'number' => next_document_number('invoice', 'INV'),
            'order_id' => $data['order_id'] ?? null,
            'customer_id' => $data['customer_id'],
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

        app(NotificationService::class)->notifyStaff(
            'sales.invoices.view',
            'Sales invoice issued',
            'Invoice '.$invoice->number.' for '.money($invoice->total, $invoice->currency).' was issued to '.($invoice->customer?->company_name ?? 'a customer').'.',
            'info',
            route('sales.invoices.show', $invoice),
            auth()->id(),
        );

        return redirect()->route('sales.invoices.index')
            ->with('toasts', [['type' => 'success', 'message' => "Invoice {$invoice->number} created."]]);
    }

    public function edit(SalesInvoice $invoice): View
    {
        $invoice->load(['customer', 'items.product', 'payments.bankAccount', 'statusEvents.user', 'creditNotes']);
        $customers = SalesCustomer::query()->orderBy('company_name')->get();
        $products = Product::query()->where('is_active', true)->orderBy('name')->get();
        $maxDiscount = $this->getMaxDiscountForUser();
        $bankAccounts = BankAccount::query()->active()->orderBy('name')->get();

        return view('sales.invoices.edit', compact('invoice', 'customers', 'products', 'maxDiscount', 'bankAccounts'));
    }

    public function show(SalesInvoice $invoice): View
    {
        $invoice->load(['customer', 'items.product', 'payments.bankAccount', 'statusEvents.user', 'creditNotes']);

        return view('sales.invoices.show', compact('invoice'));
    }

    public function pdf(SalesInvoice $invoice): Response
    {
        $this->preparePdf();
        $invoice->load(['customer', 'items.product', 'payments', 'creditNotes']);

        $template = in_array($invoice->template, ['classic', 'modern', 'minimal', 'corporate'], true) ? $invoice->template : 'classic';
        $view = 'sales.invoices.pdf'.($template === 'classic' ? '' : '-'.$template);

        $html = view($view, compact('invoice'))->render();

        $pdf = Pdf::loadHTML($html)->setPaper('a4', 'portrait');

        return $pdf->stream('invoice-'.$invoice->number.'.pdf');
    }

    public function updateTemplate(Request $request, SalesInvoice $invoice): RedirectResponse
    {
        $data = $request->validate([
            'template' => ['required', Rule::in(array_keys(SalesInvoice::templateOptions()))],
        ]);

        $invoice->update(['template' => $data['template']]);

        return redirect()->route('sales.invoices.show', $invoice)
            ->with('toasts', [['type' => 'success', 'message' => 'Invoice template set to '.ucfirst($data['template']).'.']]);
    }

    public function update(Request $request, SalesInvoice $invoice): RedirectResponse
    {
        $data = $this->validateData($request);
        $this->validateDiscountLimits($request->input('items', []));

        $totals = DocumentItems::sync($invoice, $request->input('items', []));

        // Editing an invoice must not strand it already overpaid. recordPayment
        // refuses an amount above the balance, but an edit can push the total
        // below what has already been received — leaving paid_amount > total,
        // a negative balance, and isPaid() reporting true on the strength of it.
        $paid = (float) $invoice->paid_amount;

        if ($paid > $totals['total']) {
            return back()
                ->withInput()
                ->with('toasts', [[
                    'type' => 'danger',
                    'message' => 'The new total ('.money($totals['total'], $invoice->currency).') is below the amount already paid ('.money($paid, $invoice->currency).'). Remove or refund a payment first.',
                ]]);
        }

        DB::transaction(function () use ($invoice, $data, $totals) {
            $invoice->update([
                'order_id' => $data['order_id'] ?? null,
                'customer_id' => $data['customer_id'],
                'issue_date' => $data['issue_date'],
                'due_date' => $data['due_date'] ?? null,
                'status' => $data['status'],
                'currency' => $data['currency'] ?? null,
                'exchange_rate' => exchange_rate_for($data['currency'] ?? null),
                'notes' => $data['notes'] ?? null,
                'subtotal' => $totals['subtotal'],
                'tax_amount' => $totals['tax'],
                'total' => $totals['total'],
            ]);

            $this->recalculateStatus($invoice->fresh());
        });

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
            'bank_account_id' => ['nullable', 'integer', Rule::exists('bank_accounts', 'id')],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            DB::transaction(function () use ($data, $invoice): void {
                $invoice = SalesInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
                if ((float) $data['amount'] > $invoice->balance()) {
                    throw new \RuntimeException('Payment exceeds the outstanding balance of '.money($invoice->balance(), $invoice->currency).'.');
                }

                SalesPayment::create([
                    'number' => next_document_number('sales_payment', 'RC'),
                    'invoice_id' => $invoice->id,
                    'customer_id' => $invoice->customer_id,
                    'bank_account_id' => $data['bank_account_id'] ?? null,
                    'amount' => $data['amount'],
                    'payment_date' => $data['payment_date'],
                    'method' => $data['method'],
                    'reference' => $data['reference'] ?? null,
                    'currency' => $invoice->currency,
                    'exchange_rate' => $invoice->exchange_rate ?? exchange_rate_for($invoice->currency, $data['payment_date']),
                    'notes' => $data['notes'] ?? null,
                ]);

                $invoice->update(['paid_amount' => round((float) $invoice->paid_amount + (float) $data['amount'], 2)]);
                $this->recalculateStatus($invoice);
            });
        } catch (\RuntimeException $e) {
            return back()->withInput()
                ->with('toasts', [['type' => 'danger', 'message' => $e->getMessage()]]);
        }

        app(NotificationService::class)->notifyStaff(
            'sales.invoices.view',
            'Payment received',
            money($data['amount'], $invoice->currency).' was received against invoice '.$invoice->number.' from '.($invoice->customer?->company_name ?? 'a customer').'.',
            'success',
            route('sales.invoices.show', $invoice),
            auth()->id(),
        );

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
