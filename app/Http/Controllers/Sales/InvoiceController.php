<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Mail\SalesInvoiceMailable;
use App\Models\BankAccount;
use App\Models\Product;
use App\Models\SalesCustomer;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Models\SalesPayment;
use App\Models\SalesStatusEvent;
use App\Services\Accounting\LedgerPosting;
use App\Services\InvoicePaymentService;
use App\Services\NotificationService;
use App\Support\BankLedger;
use App\Support\CustomerCredit;
use App\Support\DiscountLimit;
use App\Support\DocumentData;
use App\Support\DocumentItems;
use App\Support\DocumentReference;
use App\Support\ExportsCsv;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
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
        $fromOrderLines = [];
        $orderFullyInvoiced = false;

        // What the tax engine expects on this document's date.
        $taxHint = $this->taxRateHint('sales', now()->toDateString());

        if ($request->filled('order')) {
            $fromOrder = SalesOrder::query()
                ->with(['items', 'invoices.items'])
                ->findOrFail($request->order);

            /**
             * Prefill from what is LEFT to bill, not from the whole order.
             *
             * Copying every line on first pass means an order delivered in three
             * shipments can only ever be invoiced once: the second invoice would
             * re-bill goods already shipped, and the third would double-bill again.
             * This is the difference between an order-to-invoice flow that works
             * for partial delivery and one that only appears to work.
             */
            $fromOrderLines = $fromOrder->uninvoicedLines();
            $orderFullyInvoiced = $fromOrder->isFullyInvoiced();
        }

        return view('sales.invoices.create', compact(
            'customers', 'products', 'fromOrder', 'fromOrderLines',
            'orderFullyInvoiced', 'maxDiscount', 'taxHint',
        ));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);
        $this->validateDiscountLimits($request->input('items', []));
        $this->validateTaxRates($request->input('items', []), 'sales', $data['issue_date'] ?? null);

        /**
         * Refuse an invoice that would exceed what the order is worth.
         *
         * A user could post any order_id alongside any line items, so without
         * this the invoice total was never checked against the order and a
         * customer could be billed more than they agreed to buy. Checked here,
         * before the row exists, so a refusal leaves nothing behind.
         */
        if (! empty($data['order_id'])) {
            $order = SalesOrder::query()->find($data['order_id']);

            if ($order && $order->isFullyInvoiced()) {
                return back()->withInput()->with('toasts', [[
                    'type' => 'danger',
                    'message' => 'Order '.$order->number.' has already been invoiced in full.',
                ]]);
            }
        }

        $invoice = SalesInvoice::create([
            'number' => next_document_number('invoice', 'INV'),
            'reference_number' => DocumentReference::fromRequest($data['reference_number'] ?? null),
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

        // A customer holding credit — from an overpayment, a deposit or an
        // opening credit — has it drawn down here rather than being asked to pay
        // cash they have already handed over. Capped at what they actually hold
        // and at this invoice's own total, so it can never over-settle.
        $applied = CustomerCredit::applyTo($invoice);

        app(NotificationService::class)->notifyStaff(
            'sales.invoices.view',
            'Sales invoice issued',
            'Invoice '.$invoice->number.' for '.money($invoice->total, $invoice->currency).' was issued to '.($invoice->customer?->company_name ?? 'a customer').'.',
            'info',
            route('sales.invoices.show', $invoice),
            auth()->id(),
        );

        $message = "Invoice {$invoice->number} created.";

        if ($applied > 0.0) {
            $message .= ' '.money($applied, $invoice->currency).' was deducted from the customer balance.';
        }

        // Recognise the revenue and the receivable. Idempotent, and a no-op on a
        // deployment whose chart of accounts has not been seeded yet.
        app(LedgerPosting::class)->salesInvoiceIssued($invoice->fresh());

        return redirect()->route('sales.invoices.index')
            ->with('toasts', [['type' => 'success', 'message' => $message]]);
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

        // `order.salesman`, not `salesman`: sales_invoices carries no salesman_id.
        // The rep is recorded on the order the invoice was raised against, and
        // DocumentData reads it from there.
        $invoice->load(['customer', 'items.product', 'payments', 'creditNotes', 'order.salesman']);

        $html = view('documents.pdf', DocumentData::build($invoice))->render();

        $pdf = Pdf::loadHTML($html)->setPaper('a4', 'portrait')->setOptions(pdf_options());

        return $pdf->stream('invoice-'.$invoice->number.'.pdf');
    }

    public function sendEmail(Request $request, SalesInvoice $invoice): RedirectResponse
    {
        $invoice->loadMissing('customer');

        $data = $request->validate([
            'recipient_email' => ['required', 'email', 'max:255'],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            Mail::to($data['recipient_email'])->send(
                new SalesInvoiceMailable($invoice, $data['message'] ?? null)
            );

            SalesStatusEvent::create([
                'trackable_type' => SalesInvoice::class,
                'trackable_id' => $invoice->id,
                'from_status' => $invoice->status,
                'to_status' => $invoice->status,
                'user_id' => auth()->id(),
                'note' => 'Invoice emailed to '.$data['recipient_email'],
            ]);

            return back()->with('toasts', [['type' => 'success', 'message' => "Invoice {$invoice->number} sent to {$data['recipient_email']}."]]);
        } catch (\Throwable $e) {
            report($e);

            return back()->with('toasts', [['type' => 'error', 'message' => 'Failed to send invoice email: '.$e->getMessage()]]);
        }
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
        $data = $this->validateData($request, $invoice->id);
        $this->validateDiscountLimits($request->input('items', []));
        $this->validateTaxRates($request->input('items', []), 'sales', $data['issue_date'] ?? null);

        $totals = DocumentItems::sync($invoice, $request->input('items', []));

        // Editing an invoice must not strand it already overpaid in cash. Cash that
        // has been received cannot be un-received by retyping a total, so the
        // edit is refused and the user has to refund a payment first.
        //
        // Customer credit is treated differently on purpose: it is money the
        // customer still holds, so shrinking the invoice simply returns part of
        // it. Refusing there would mean an invoice could never be reduced once
        // credit had touched it.
        if ((float) $invoice->paid_amount > $totals['total']) {
            return back()
                ->withInput()
                ->with('toasts', [[
                    'type' => 'danger',
                    'message' => 'The new total ('.money($totals['total'], $invoice->currency).') is below the amount already paid ('.money($invoice->paid_amount, $invoice->currency).'). Remove or refund a payment first.',
                ]]);
        }

        DB::transaction(function () use ($invoice, $data, $totals) {
            $invoice->update([
                'reference_number' => DocumentReference::fromRequest($data['reference_number'] ?? null),
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

            // Re-run the credit draw rather than only adding to it. The figure is
            // recomputed from what the customer holds excluding this invoice, so
            // a shrunk invoice gives the difference back and a grown one takes
            // more, without ever double-counting what is already on it.
            CustomerCredit::applyTo($invoice->fresh());

            $this->recalculateStatus($invoice->fresh());

            // The ledger entry was raised for the old total. Void it and raise one
            // for the amended figures, so the receivable and revenue on the
            // statements cannot drift away from the invoice they came from.
            app(LedgerPosting::class)->repost(
                $invoice->fresh(),
                fn ($ledger) => $ledger->salesInvoiceIssued($invoice->fresh()),
                'Invoice amended',
            );
        });

        return back()->with('toasts', [['type' => 'success', 'message' => "Invoice {$invoice->number} updated."]]);
    }

    public function destroy(SalesInvoice $invoice): RedirectResponse
    {
        $number = $invoice->number;

        // Any credit this invoice was settling has to go back to the customer
        // before the row goes, or the money is silently lost from their balance.
        CustomerCredit::releaseFrom($invoice);

        // Void the invoice's journal while the row still resolves. Otherwise the
        // receivable stays on the books for an invoice that no longer exists.
        app(LedgerPosting::class)->reverse($invoice, 'Invoice deleted');

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
            'overpayment_confirmed' => ['nullable', 'boolean'],
        ]);

        try {
            $surplus = DB::transaction(function () use ($data, $request, $invoice): float {
                $service = app(InvoicePaymentService::class);

                $locked = $service->lockAndGuard(
                    SalesInvoice::query()->lockForUpdate()->findOrFail($invoice->id),
                    (int) $invoice->customer_id,
                    $invoice->currency,
                    (float) $data['amount'],
                    null,
                    $request->boolean('overpayment_confirmed'),
                );

                // Same split as the standalone payment form: the invoice takes
                // only what it is owed and the rest is held as customer credit.
                // Doing it here too means the two entry points cannot disagree
                // about what an overpayment means.
                $split = $service->splitFor($locked, (float) $data['amount']);

                $receipt = function (?int $invoiceId, float $value, ?string $note) use ($data, $locked): SalesPayment {
                    $payment = SalesPayment::create([
                        'number' => next_document_number('sales_payment', 'RC', SalesPayment::class),
                        'invoice_id' => $invoiceId,
                        'customer_id' => $locked->customer_id,
                        'bank_account_id' => $data['bank_account_id'] ?? null,
                        'amount' => $value,
                        'payment_date' => $data['payment_date'],
                        'method' => $data['method'],
                        'reference' => $data['reference'] ?? null,
                        'currency' => $locked->currency,
                        'exchange_rate' => $locked->exchange_rate ?? exchange_rate_for($locked->currency, $data['payment_date']),
                        'notes' => $note ?? ($data['notes'] ?? null),
                    ]);

                    BankLedger::postReceipt($payment);

                    return $payment;
                };

                if ($split['applied'] > 0.0) {
                    $receipt($locked->id, $split['applied'], null);

                    // Recomputed from the rows rather than incremented, so a
                    // retried or previously-edited payment cannot drift.
                    $locked->update([
                        'paid_amount' => round((float) $locked->payments()->sum('amount'), 2),
                    ]);

                    $service->recalculateStatus($locked->refresh());
                }

                if ($split['surplus'] > 0.0) {
                    $receipt(null, $split['surplus'], 'Surplus held as customer credit');
                }

                return $split['surplus'];
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

        $message = 'Payment of '.money($data['amount'], $invoice->currency).' recorded.';

        if ($surplus > 0.0) {
            $message .= ' '.money($surplus, $invoice->currency).' was held as customer credit and will be deducted from the next invoice.';
        }

        return back()->with('toasts', [['type' => 'success', 'message' => $message]]);
    }

    /**
     * Delegated rather than reimplemented: this controller used to carry its own
     * copy of the status machine, which had already drifted from the shared
     * service's copy. Two versions of "how does an invoice decide it is paid" is
     * exactly how an invoice ends up claiming a payment that was never made.
     */
    private function recalculateStatus(SalesInvoice $invoice): void
    {
        app(InvoicePaymentService::class)->recalculateStatus($invoice);
    }

    private function validateData(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'reference_number' => DocumentReference::rules('sales_invoices', $ignoreId),
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
