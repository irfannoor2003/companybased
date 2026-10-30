<?php

namespace App\Http\Controllers\Sales;

use App\Support\ReceiptDocument;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\SalesCustomer;
use App\Models\SalesInvoice;
use App\Models\SalesPayment;
use App\Models\SalesStatusEvent;
use App\Services\InvoicePaymentService;
use App\Support\BankLedger;
use App\Support\DocumentReference;
use App\Support\ExportsCsv;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentController extends Controller
{
    use ExportsCsv;

    public function index(Request $request): View
    {
        $payments = SalesPayment::query()
            ->with(['customer', 'invoice'])
            ->when($request->filled('search'), fn ($q) => $q->where('number', 'like', "%{$request->search}%"))
            ->when($request->filled('customer'), fn ($q) => $q->where('customer_id', $request->customer))
            ->when($request->filled('method'), fn ($q) => $q->where('method', $request->method))
            ->latest('payment_date')
            ->paginate(20)
            ->withQueryString();

        $customers = SalesCustomer::query()->orderBy('company_name')->get();

        return view('sales.payments.index', compact('payments', 'customers'));
    }

    public function create(Request $request): View
    {
        $fromInvoice = null;
        if ($request->filled('invoice')) {
            $fromInvoice = SalesInvoice::query()->with(['customer'])->findOrFail($request->invoice);
        }

        $customers = SalesCustomer::query()->orderBy('company_name')->get();

        // Resolved after $fromInvoice exists, and passed in so the invoice this
        // payment is being raised for is always listed — including when it has
        // already been settled and so is excluded from the unpaid ones.
        $invoices = $this->openInvoices($fromInvoice?->id);

        $bankAccounts = BankAccount::query()->active()->orderBy('name')->get();

        return view('sales.payments.create', compact('customers', 'invoices', 'fromInvoice', 'bankAccounts'));
    }

    public function show(SalesPayment $payment): View
    {
        $payment->load(['customer', 'invoice', 'bankAccount']);

        return view('sales.payments.show', compact('payment'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

        try {
            [$payment, $surplus] = DB::transaction(function () use ($data, $request): array {
                $service = app(InvoicePaymentService::class);
                $invoice = null;
                $amount = (float) $data['amount'];
                $surplus = 0.0;

                if (! empty($data['invoice_id'] ?? null)) {
                    $invoice = SalesInvoice::query()->findOrFail($data['invoice_id'] ?? null);

                    // The surplus is only ever accepted once the operator has
                    // confirmed it. Without the confirmation the strict guard
                    // still throws, so a direct POST cannot slip a mistyped amount
                    // past the confirmation dialog the form shows.
                    $invoice = $service->lockAndGuard(
                        $invoice,
                        (int) $data['customer_id'],
                        $data['currency'] ?? null,
                        $amount,
                        null,
                        $request->boolean('overpayment_confirmed'),
                    );

                    $split = $service->splitFor($invoice, $amount);

                    $amount = $split['applied'];
                    $surplus = $split['surplus'];
                    $data['currency'] = $invoice->currency;

                    // Nothing left to settle in cash means the invoice is already
                    // covered by the customer's credit. The money is an advance,
                    // not a payment against a bill that is not owed, so the
                    // receipt is left unallocated.
                    if ($amount <= 0.0) {
                        $invoice = null;
                    }
                }

                $payment = null;

                if ($amount > 0.0) {
                    $payment = $this->recordReceipt($data, $invoice?->id, $amount);

                    if ($invoice) {
                        $service->applyPaidAmount($invoice, $amount);
                        $service->recalculateStatus($invoice);
                    }
                }

                if ($surplus > 0.0) {
                    // A second receipt with no invoice against it. This is already
                    // how an advance is represented, and SalesCustomer::balance()
                    // subtracts it, so the surplus shows as credit held and the
                    // next invoice draws it down automatically.
                    //
                    // Created unconditionally rather than only when no invoice
                    // receipt exists — the surplus is money that arrived whether
                    // or not it was needed by this particular bill.
                    $advance = $this->recordReceipt($data, null, $surplus, 'Surplus held as customer credit');

                    $payment ??= $advance;
                }

                return [$payment, $surplus];
            });
        } catch (QueryException|\RuntimeException $e) {
            if ($e instanceof QueryException) {
                report($e);
            }

            return back()->withInput()
                ->with('toasts', [['type' => 'danger', 'message' => $e instanceof QueryException ? 'Could not record the payment. Please try again — if the problem persists, contact support.' : $e->getMessage()]]);
        }

        $message = "Payment {$payment->number} recorded.";

        if ($surplus > 0.0) {
            $message .= ' '.money($surplus, $payment->currency).' was held as customer credit and will be deducted from the next invoice.';
        }

        return redirect()->route('sales.sales_payments.index')
            ->with('toasts', [['type' => 'success', 'message' => $message]]);
    }

    public function edit(SalesPayment $payment): View
    {
        $payment->load(['customer', 'invoice']);

        return view('sales.payments.edit', [
            'payment' => $payment,
            'customers' => SalesCustomer::query()->orderBy('company_name')->get(),
            'invoices' => $this->openInvoices($payment->invoice_id),
            'bankAccounts' => BankAccount::query()->active()->orderBy('name')->get(),
        ]);
    }

    /**
     * Invoices a payment may be pointed at.
     *
     * Unpaid invoices, plus whichever invoice this payment is already recorded
     * against even if it has since been settled. That exception is the whole
     * point: a payment against an invoice that later became `paid` used to drop
     * out of this list, which left no `<option>` selected, so the browser fell
     * back to the first entry and submitting any edit at all — even a typo fix in
     * the reference — was read as re-pointing the receipt.
     */
    private function openInvoices(?int $alsoInclude = null): \Illuminate\Support\Collection
    {
        return SalesInvoice::query()
            ->where(function ($query) use ($alsoInclude): void {
                $query->where('status', '!=', 'paid');

                if ($alsoInclude) {
                    $query->orWhere('id', $alsoInclude);
                }
            })
            ->orderBy('issue_date')
            ->get();
    }

    public function update(Request $request, SalesPayment $payment): RedirectResponse
    {
        $data = $this->validateData($request, $payment->id);

        $number = $payment->number;

        try {
            $reassigned = DB::transaction(function () use ($data, $payment, $request, $number): bool {
                $service = app(InvoicePaymentService::class);

                $payment = SalesPayment::query()->lockForUpdate()->findOrFail($payment->id);

                $oldInvoice = $payment->invoice_id
                    ? SalesInvoice::query()->lockForUpdate()->find($payment->invoice_id)
                    : null;

                $newInvoiceId = $data['invoice_id'] ?? null;
                $amount = (float) $data['amount'];
                $invoice = null;
                $surplus = 0.0;

                if ($newInvoiceId) {
                    // Re-pointing is allowed, as it is in Odoo, Xero and Sage: a
                    // receipt landing on the wrong bill is common and the fix
                    // should not require deleting the receipt and losing its bank
                    // reference. The new invoice is still locked and guarded, so
                    // the move cannot overfill it or cross customers.
                    $invoice = $service->lockAndGuard(
                        SalesInvoice::query()->findOrFail($newInvoiceId),
                        (int) $data['customer_id'],
                        $data['currency'] ?? null,
                        $amount,
                        $payment->id,
                        $request->boolean('overpayment_confirmed'),
                    );

                    $split = $service->splitFor($invoice, $amount, $payment->id);

                    $amount = $split['applied'];
                    $surplus = $split['surplus'];

                    if ($amount <= 0.0) {
                        $invoice = null;
                    }
                }

                $payment->update([
                    'reference_number' => DocumentReference::fromRequest($data['reference_number'] ?? null),
                    'invoice_id' => $invoice?->id,
                    'customer_id' => $invoice?->customer_id ?? $data['customer_id'],
                    'bank_account_id' => $data['bank_account_id'] ?? null,
                    'amount' => $amount,
                    'payment_date' => $data['payment_date'],
                    'method' => $data['method'],
                    'reference' => $data['reference'] ?? null,
                    'currency' => $invoice?->currency ?? ($data['currency'] ?? null),
                    'exchange_rate' => exchange_rate_for($invoice?->currency ?? ($data['currency'] ?? null), $data['payment_date']),
                    'notes' => $data['notes'] ?? null,
                ]);

                // The ledger entry was raised for the old amount and date. Void it
                // and raise one for the amended figures, so the statements cannot
                // drift away from the receipt they came from. Void-and-repost
                // rather than edit-in-place: both the mistake and its correction
                // stay on the audit trail.
                app(\App\Services\Accounting\LedgerPosting::class)->repost(
                    $payment->fresh(),
                    fn ($ledger) => $ledger->paymentReceived($payment->fresh()),
                    'Receipt amended',
                );

                // Both invoices are recomputed from their own payment rows, not
                // just the one being moved to. The invoice being moved away from
                // is the one that silently goes wrong otherwise: its paid_amount
                // still counts this receipt, so it stays short-paid while the
                // customer looks settled on both.
                if ($invoice) {
                    $this->resettleInvoice($service, $invoice);
                }

                $moved = $oldInvoice && (int) $oldInvoice->id !== (int) ($invoice?->id ?? 0);

                if ($moved) {
                    $this->resettleInvoice($service, $oldInvoice);

                    foreach ([[$oldInvoice, 'left'], [$invoice, 'received']] as [$moved_invoice, $direction]) {
                        SalesStatusEvent::create([
                            'trackable_type' => SalesInvoice::class,
                            'trackable_id' => $moved_invoice->id,
                            'from_status' => $moved_invoice->status,
                            'to_status' => $moved_invoice->status,
                            'user_id' => auth()->id(),
                            'note' => 'Payment '.$number.' was '.$direction.' by this invoice',
                        ]);
                    }
                }

                // The old leg is dropped and a new one written so the bank ledger
                // follows the payment instead of drifting from it.
                BankLedger::reversePayment($payment);
                BankLedger::postReceipt($payment->fresh()->load('invoice'));

                if ($surplus > 0.0) {
                    $this->recordReceipt($data, null, $surplus, 'Surplus held as customer credit');
                }

                return (bool) $moved;
            });
        } catch (QueryException|\RuntimeException $e) {
            if ($e instanceof QueryException) {
                report($e);
            }

            return back()->withInput()
                ->with('toasts', [['type' => 'danger', 'message' => $e instanceof QueryException ? 'Could not update the payment. Please try again.' : $e->getMessage()]]);
        }

        if ($reassigned) {
            session()->flash('payment_reassigned', $number);
        }

        $redirectTo = $request->input('redirect_to');
        if (! is_string($redirectTo) || ! str_starts_with($redirectTo, url('/'))) {
            $redirectTo = $payment->invoice_id
                ? route('sales.invoices.edit', $payment->invoice_id)
                : route('sales.sales_payments.index');
        }

$message = "Payment {$number} updated.";

        if ($reassigned) {
            $message = "Payment {$number} moved to ".($payment->fresh()->invoice?->number ?? 'no invoice').'.';
        }

        return redirect()->to($redirectTo)
            ->with('toasts', [['type' => 'success', 'message' => $message]]);
    }

    /**
     * Write one receipt row and put it in the bank ledger.
     *
     * Shared by the invoice-applied part of a payment and the surplus that becomes
     * customer credit, because the two are the same kind of thing — money
     * received — and differ only in whether an invoice is attached.
     *
     * @param  string|null  $note  Overrides the operator's notes, used to say why
     *                             an unallocated receipt exists.
     */
    private function recordReceipt(array $data, ?int $invoiceId, float $amount, ?string $note = null): SalesPayment
    {
        $payment = SalesPayment::create([
            'number' => next_document_number('sales_payment', 'RC', SalesPayment::class),
            'reference_number' => DocumentReference::fromRequest($data['reference_number'] ?? null),
            'invoice_id' => $invoiceId,
            'customer_id' => $data['customer_id'],
            'bank_account_id' => $data['bank_account_id'] ?? null,
            'amount' => $amount,
            'payment_date' => $data['payment_date'],
            'method' => $data['method'],
            'reference' => $data['reference'] ?? null,
            'currency' => $data['currency'] ?? null,
            'exchange_rate' => exchange_rate_for($data['currency'] ?? null, $data['payment_date']),
            'notes' => $note ?? ($data['notes'] ?? null),
        ]);

        // Real money in the selected account, so it has to reach the bank ledger
        // too or the account balance and the bank statement under-report it.
        BankLedger::postReceipt($payment->load('invoice'));

        // General ledger: the money arrived (Dr cash) and the customer's debt falls
        // (Cr receivable). Posted here rather than in store() so the applied
        // portion and the surplus advance are both covered, and so a receipt that
        // moves between invoices is still posted exactly once.
        app(\App\Services\Accounting\LedgerPosting::class)->paymentReceived($payment->fresh());

        return $payment;
    }

    /**
     * Re-derive an invoice's paid_amount from its payment rows and move its
     * status to match.
     */
    private function resettleInvoice(InvoicePaymentService $service, SalesInvoice $invoice): void
    {
        $invoice->update([
            'paid_amount' => round((float) $invoice->payments()->sum('amount'), 2),
        ]);

        $service->recalculateStatus($invoice->refresh());
    }

    public function destroy(SalesPayment $payment): RedirectResponse
    {
        $number = $payment->number;

        try {
            DB::transaction(function () use ($payment): void {
                $payment = SalesPayment::query()->lockForUpdate()->findOrFail($payment->id);
                $invoice = $payment->invoice_id
                    ? SalesInvoice::query()->lockForUpdate()->findOrFail($payment->invoice_id)
                    : null;
                // Reversed before the delete so the polymorphic reference still
                // resolves, and inside the same transaction so the bank ledger can
                // never keep a leg for a payment that no longer exists.
                BankLedger::reversePayment($payment);
                $payment->delete();

                // Void the receipt's journal before the row goes, while the
                // polymorphic reference still resolves. Without this the ledger
                // kept "cash in / receivable down" for a receipt that no longer
                // exists, so the trial balance drifted permanently every time a
                // payment was deleted.
                app(\App\Services\Accounting\LedgerPosting::class)
                    ->reverse($payment, 'Receipt deleted');

                if ($invoice) {
                    $invoice->update(['paid_amount' => round((float) $invoice->payments()->sum('amount'), 2)]);
                    // Via the service, as store() and update() do. This method was
                    // calling a $this->recalculateStatus() that does not exist on
                    // the controller, so deleting any payment 500'd and the
                    // transaction rolled back — the payment could not be deleted
                    // at all.
                    app(InvoicePaymentService::class)->recalculateStatus($invoice);
                }
            });
        } catch (QueryException|\RuntimeException $e) {
            if ($e instanceof QueryException) {
                report($e);
            }

            return back()->with('toasts', [['type' => 'danger', 'message' => 'Could not delete the payment. Please try again.']]);
        }

        return redirect()->route('sales.sales_payments.index')
            ->with('toasts', [['type' => 'success', 'message' => "Payment {$number} deleted."]]);
    }

    public function pdf(SalesPayment $payment)
    {
        $payment->load(['customer', 'invoice']);

        $html = ReceiptDocument::salesPayment($payment)->render();

        $pdf = Pdf::loadHTML($html)->setPaper('a4', 'portrait')->output();

        $filename = 'payment-'.$payment->number.'.pdf';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Content-Length' => strlen($pdf),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $payments = SalesPayment::query()
            ->with(['customer', 'invoice'])
            ->when($request->filled('search'), fn ($q) => $q->where('number', 'like', "%{$request->search}%"))
            ->when($request->filled('customer'), fn ($q) => $q->where('customer_id', $request->customer))
            ->when($request->filled('method'), fn ($q) => $q->where('method', $request->method))
            ->latest('payment_date')
            ->get();

        return $this->streamCsv('sales-payments-'.now()->format('Y-m-d').'.csv', ['Number', 'Customer', 'Invoice', 'Date', 'Method', 'Reference', 'Amount', 'Currency'], $payments->map(fn (SalesPayment $p) => [
            $p->number,
            $p->customer?->company_name,
            $p->invoice?->number ?? '—',
            $p->payment_date?->format('Y-m-d'),
            ucfirst(str_replace('_', ' ', $p->method)),
            $p->reference,
            $p->amount,
            $p->currency,
        ]));
    }

    private function validateData(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'reference_number' => DocumentReference::rules('sales_payments', $ignoreId),
            'invoice_id' => ['nullable', 'integer', Rule::exists('sales_invoices', 'id')],
            'customer_id' => ['required', 'integer', Rule::exists('sales_customers', 'id')],
            'bank_account_id' => ['nullable', 'integer', Rule::exists('bank_accounts', 'id')],
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_date' => ['required', 'date'],
            'method' => ['required', Rule::in(SalesPayment::methodOptions())],
            'reference' => ['nullable', 'string', 'max:120'],
            'currency' => ['nullable', 'string', 'max:8'],
            'notes' => ['nullable', 'string', 'max:1000'],

            // Set by the form only after the operator has acknowledged that an
            // amount above the invoice balance will leave the surplus on the
            // customer's account. Absent or false, an overpayment is refused
            // server-side, so the dialog is not the only thing standing between a
            // mistyped figure and a wrong ledger.
            'overpayment_confirmed' => ['nullable', 'boolean'],
        ]);
    }
}
