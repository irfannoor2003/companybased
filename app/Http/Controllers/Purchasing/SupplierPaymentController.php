<?php

namespace App\Http\Controllers\Purchasing;

use App\Support\ReceiptDocument;

use App\Services\Accounting\LedgerPostingFailure;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Services\InvoicePaymentService;
use App\Support\BankLedger;
use App\Support\DocumentReference;
use App\Support\ExportsCsv;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupplierPaymentController extends Controller
{
    use ExportsCsv;

    public function index(Request $request): View
    {
        $payments = SupplierPayment::query()
            ->with(['supplier', 'invoice'])
            ->when($request->filled('search'), fn ($q) => $q->where('number', 'like', "%{$request->search}%"))
            ->when($request->filled('supplier'), fn ($q) => $q->where('supplier_id', $request->supplier))
            ->when($request->filled('method'), fn ($q) => $q->where('method', $request->method))
            ->latest('payment_date')
            ->paginate(20)
            ->withQueryString();

        $suppliers = Supplier::query()->orderBy('company_name')->get();

        return view('suppliers.supplier_payments.index', compact('payments', 'suppliers'));
    }

    public function show(SupplierPayment $payment): View
    {
        $payment->load(['supplier', 'invoice', 'bankAccount']);

        return view('suppliers.supplier_payments.show', compact('payment'));
    }

    public function create(Request $request): View
    {
        $suppliers = Supplier::query()->orderBy('company_name')->get();
        $invoices = PurchaseInvoice::query()
            ->where('status', '!=', 'paid')
            ->orderBy('issue_date')
            ->get();
        $bankAccounts = BankAccount::query()->active()->orderBy('name')->get();

        $fromInvoice = null;
        if ($request->filled('invoice')) {
            $fromInvoice = PurchaseInvoice::query()->with(['supplier'])->findOrFail($request->invoice);
        }

        return view('suppliers.supplier_payments.create', compact('suppliers', 'invoices', 'fromInvoice', 'bankAccounts'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

        try {
            $payment = DB::transaction(function () use ($data): SupplierPayment {
                $service = app(InvoicePaymentService::class);
                $invoice = null;

                if (! empty($data['invoice_id'] ?? null)) {
                    $invoice = $service->lockAndGuard(
                        PurchaseInvoice::query()->findOrFail($data['invoice_id'] ?? null),
                        (int) $data['supplier_id'],
                        $data['currency'] ?? null,
                        (float) $data['amount'],
                    );

                    $data['currency'] = $invoice->currency;
                }

                $payment = SupplierPayment::create([
                    'number' => next_document_number('supplier_payment', 'SP', SupplierPayment::class),
                    'reference_number' => DocumentReference::fromRequest($data['reference_number'] ?? null),
                    'invoice_id' => $invoice?->id,
                    'supplier_id' => $invoice?->supplier_id ?? $data['supplier_id'],
                    'bank_account_id' => $data['bank_account_id'] ?? null,
                    'amount' => $data['amount'],
                    'payment_date' => $data['payment_date'],
                    'method' => $data['method'],
                    'reference' => $data['reference'] ?? null,
                    'currency' => $data['currency'] ?? null,
                    'exchange_rate' => exchange_rate_for($data['currency'] ?? null, $data['payment_date']),
                    'notes' => $data['notes'] ?? null,
                ]);

                if ($invoice) {
                    $service->applyPaidAmount($invoice, (float) $data['amount']);
                    $service->recalculateStatus($invoice);
                }

                BankLedger::postDisbursement($payment->load('invoice'));

                app(\App\Services\Accounting\LedgerPosting::class)->paymentMade($payment->fresh());

                return $payment;
            });
        } catch (LedgerPostingFailure $e) {
            // A business-rule refusal from the ledger: show it and keep the input.
            return back()->withInput()
                ->with('toasts', [['type' => 'danger', 'message' => $e->getMessage()]]);
        } catch (\RuntimeException $e) {
            // Anything else that went wrong inside the transaction -- an unbalanced
            // posting, a missing ledger account -- must not be dressed up as a
            // routine validation message. It used to be caught here and rendered as
            // a toast, so the payment silently rolled back and the operator saw
            // "failed" with no error and no trace in the log.
            report($e);

            return back()->withInput()
                ->with('toasts', [[
                    'type' => 'danger',
                    'message' => 'The payment could not be recorded and the ledger entry failed. Nothing was saved — please retry or contact support.',
                ]]);
        }

        return redirect()->route('suppliers.supplier_payments.index')
            ->with('toasts', [['type' => 'success', 'message' => "Payment {$payment->number} recorded."]]);
    }

    public function edit(SupplierPayment $payment): View
    {
        $payment->load(['supplier', 'invoice']);
        $suppliers = Supplier::query()->orderBy('company_name')->get();
        $invoices = PurchaseInvoice::query()
            ->where('status', '!=', 'paid')
            ->orderBy('issue_date')
            ->get();
        $bankAccounts = BankAccount::query()->active()->orderBy('name')->get();

        return view('suppliers.supplier_payments.edit', compact('payment', 'suppliers', 'invoices', 'bankAccounts'));
    }

    public function update(Request $request, SupplierPayment $payment): RedirectResponse
    {
        $data = $this->validateData($request, $payment->id);

        try {
            DB::transaction(function () use ($data, $payment): void {
                $service = app(InvoicePaymentService::class);

                $payment = SupplierPayment::query()->lockForUpdate()->findOrFail($payment->id);
                $newInvoiceId = $data['invoice_id'] ?? null;

                if ((int) $newInvoiceId !== (int) $payment->invoice_id) {
                    throw new \RuntimeException('A recorded payment cannot be reassigned to another invoice.');
                }

                $invoice = null;

                if ($payment->invoice_id) {
                    // Excludes this payment from the balance check so editing an
                    // amount up to the full remaining total stays legal.
                    $invoice = $service->lockAndGuard(
                        PurchaseInvoice::query()->findOrFail($payment->invoice_id),
                        (int) $data['supplier_id'],
                        $data['currency'] ?? null,
                        (float) $data['amount'],
                        $payment->id,
                    );
                }

                $payment->update([
                    'reference_number' => DocumentReference::fromRequest($data['reference_number'] ?? null),
                    'invoice_id' => $payment->invoice_id,
                    'supplier_id' => $invoice?->supplier_id ?? $data['supplier_id'],
                    'bank_account_id' => $data['bank_account_id'] ?? null,
                    'amount' => $data['amount'],
                    'payment_date' => $data['payment_date'],
                    'method' => $data['method'],
                    'reference' => $data['reference'] ?? null,
                    'currency' => $invoice?->currency ?? ($data['currency'] ?? null),
'exchange_rate' => exchange_rate_for($invoice?->currency ?? ($data['currency'] ?? null), $data['payment_date']),
                    'notes' => $data['notes'] ?? null,
                ]);

                // Void the journal raised for the old figures and raise one for the
                // amended payment, so the payable on the ledger keeps agreeing with
                // the disbursement recorded against the bill.
                app(\App\Services\Accounting\LedgerPosting::class)->repost(
                    $payment->fresh(),
                    fn ($ledger) => $ledger->paymentMade($payment->fresh()),
                    'Supplier payment amended',
                );

                if ($invoice) {
                    $invoice->update([
                        'paid_amount' => round((float) $invoice->payments()->sum('amount'), 2),
                    ]);
                    $service->recalculateStatus($invoice);
                }

                BankLedger::reversePayment($payment);
                BankLedger::postDisbursement($payment->fresh()->load('invoice'));
            });
        } catch (QueryException|\RuntimeException $e) {
            if ($e instanceof QueryException) {
                report($e);
            }

            return back()->withInput()
                ->with('toasts', [['type' => 'danger', 'message' => $e instanceof QueryException ? 'Could not update the payment. Please try again.' : $e->getMessage()]]);
        }

        $redirectTo = $request->input('redirect_to');
        if (! is_string($redirectTo) || ! str_starts_with($redirectTo, url('/'))) {
            $redirectTo = $payment->invoice_id
                ? route('suppliers.purchase_invoices.edit', $payment->invoice_id)
                : route('suppliers.supplier_payments.index');
        }

        return redirect()->to($redirectTo)
            ->with('toasts', [['type' => 'success', 'message' => "Payment {$payment->number} updated."]]);
    }

    public function destroy(SupplierPayment $payment): RedirectResponse
    {
        $number = $payment->number;

        try {
            DB::transaction(function () use ($payment): void {
                $payment = SupplierPayment::query()->lockForUpdate()->findOrFail($payment->id);
                $invoice = $payment->invoice_id
                    ? PurchaseInvoice::query()->lockForUpdate()->findOrFail($payment->invoice_id)
                    : null;
BankLedger::reversePayment($payment);
                $payment->delete();

                // Void the disbursement's journal while the row still resolves, so
                // the payable does not stay credited for money that never left.
                app(\App\Services\Accounting\LedgerPosting::class)
                    ->reverse($payment, 'Supplier payment deleted');

                if ($invoice) {
                    $invoice->update(['paid_amount' => round((float) $invoice->payments()->sum('amount'), 2)]);
                    // Via the service, as store() and update() do. This was
                    // calling a $this->recalculateStatus() that does not exist on
                    // the controller, so deleting any payment 500'd and rolled back.
                    app(InvoicePaymentService::class)->recalculateStatus($invoice);
                }
            });
        } catch (QueryException|\RuntimeException $e) {
            if ($e instanceof QueryException) {
                report($e);
            }

            return back()->with('toasts', [['type' => 'danger', 'message' => 'Could not delete the payment. Please try again.']]);
        }

        return redirect()->route('suppliers.supplier_payments.index')
            ->with('toasts', [['type' => 'success', 'message' => "Payment {$number} deleted."]]);
    }

    public function pdf(SupplierPayment $payment): Response
    {
        $this->preparePdf();
        $payment->load(['supplier', 'invoice']);

        $html = ReceiptDocument::supplierPayment($payment)->render();

        $pdf = Pdf::loadHTML($html)->setPaper('a4', 'portrait');

        return $pdf->stream('payment-'.$payment->number.'.pdf');
    }

    public function export(Request $request): StreamedResponse
    {
        $payments = SupplierPayment::query()
            ->with(['supplier', 'invoice'])
            ->when($request->filled('search'), fn ($q) => $q->where('number', 'like', "%{$request->search}%"))
            ->when($request->filled('supplier'), fn ($q) => $q->where('supplier_id', $request->supplier))
            ->when($request->filled('method'), fn ($q) => $q->where('method', $request->method))
            ->latest('payment_date')
            ->get();

        return $this->streamCsv('supplier-payments-'.now()->format('Y-m-d').'.csv', ['Number', 'Supplier', 'Invoice', 'Date', 'Method', 'Reference', 'Amount', 'Currency'], $payments->map(fn (SupplierPayment $p) => [
            $p->number,
            $p->supplier?->company_name,
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
            'reference_number' => DocumentReference::rules('supplier_payments', $ignoreId),
            'invoice_id' => ['nullable', 'integer', Rule::exists('purchase_invoices', 'id')],
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')],
            'bank_account_id' => ['nullable', 'integer', Rule::exists('bank_accounts', 'id')],
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_date' => ['required', 'date'],
            'method' => ['required', Rule::in(SupplierPayment::methodOptions())],
            'reference' => ['nullable', 'string', 'max:120'],
            'currency' => ['nullable', 'string', 'max:8'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
    }
}
