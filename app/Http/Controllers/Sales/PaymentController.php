<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\SalesCustomer;
use App\Models\SalesInvoice;
use App\Models\SalesPayment;
use App\Services\InvoicePaymentService;
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
        $customers = SalesCustomer::query()->orderBy('company_name')->get();
        $invoices = SalesInvoice::query()
            ->where('status', '!=', 'paid')
            ->orderBy('issue_date')
            ->get();

        $fromInvoice = null;
        if ($request->filled('invoice')) {
            $fromInvoice = SalesInvoice::query()->with(['customer'])->findOrFail($request->invoice);
        }

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
            $payment = DB::transaction(function () use ($data): SalesPayment {
                $service = app(InvoicePaymentService::class);
                $invoice = null;

                if (! empty($data['invoice_id'])) {
                    $invoice = $service->lockAndGuard(
                        SalesInvoice::query()->findOrFail($data['invoice_id']),
                        (int) $data['customer_id'],
                        $data['currency'] ?? null,
                        (float) $data['amount'],
                    );

                    $data['currency'] = $invoice->currency;
                }

                $payment = SalesPayment::create([
                    'number' => next_document_number('sales_payment', 'RC', SalesPayment::class),
                    'invoice_id' => $invoice?->id,
                    'customer_id' => $invoice?->customer_id ?? $data['customer_id'],
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

                return $payment;
            });
        } catch (QueryException|\RuntimeException $e) {
            if ($e instanceof QueryException) {
                report($e);
            }

            return back()->withInput()
                ->with('toasts', [['type' => 'danger', 'message' => $e instanceof QueryException ? 'Could not record the payment. Please try again — if the problem persists, contact support.' : $e->getMessage()]]);
        }

        return redirect()->route('sales.sales_payments.index')
            ->with('toasts', [['type' => 'success', 'message' => "Payment {$payment->number} recorded."]]);
    }

    public function edit(SalesPayment $payment): View
    {
        $payment->load(['customer', 'invoice']);
        $customers = SalesCustomer::query()->orderBy('company_name')->get();
        $invoices = SalesInvoice::query()
            ->where('status', '!=', 'paid')
            ->orderBy('issue_date')
            ->get();
        $bankAccounts = BankAccount::query()->active()->orderBy('name')->get();

        return view('sales.payments.edit', compact('payment', 'customers', 'invoices', 'bankAccounts'));
    }

    public function update(Request $request, SalesPayment $payment): RedirectResponse
    {
        $data = $this->validateData($request);

        $number = $payment->number;

        try {
            DB::transaction(function () use ($data, $payment): void {
                $service = app(InvoicePaymentService::class);

                $payment = SalesPayment::query()->lockForUpdate()->findOrFail($payment->id);
                $newInvoiceId = $data['invoice_id'] ?? null;

                if ((int) $newInvoiceId !== (int) $payment->invoice_id) {
                    throw new \RuntimeException('A recorded payment cannot be reassigned to another invoice.');
                }

                $invoice = null;

                if ($payment->invoice_id) {
                    // Excludes this payment from the balance check so editing an
                    // amount up to the full remaining total stays legal.
                    $invoice = $service->lockAndGuard(
                        SalesInvoice::query()->findOrFail($payment->invoice_id),
                        (int) $data['customer_id'],
                        $data['currency'] ?? null,
                        (float) $data['amount'],
                        $payment->id,
                    );
                }

                $payment->update([
                    'invoice_id' => $payment->invoice_id,
                    'customer_id' => $invoice?->customer_id ?? $data['customer_id'],
                    'bank_account_id' => $data['bank_account_id'] ?? null,
                    'amount' => $data['amount'],
                    'payment_date' => $data['payment_date'],
                    'method' => $data['method'],
                    'reference' => $data['reference'] ?? null,
                    'currency' => $invoice?->currency ?? ($data['currency'] ?? null),
                    'exchange_rate' => exchange_rate_for($invoice?->currency ?? ($data['currency'] ?? null), $data['payment_date']),
                    'notes' => $data['notes'] ?? null,
                ]);

                if ($invoice) {
                    $invoice->update([
                        'paid_amount' => round((float) $invoice->payments()->sum('amount'), 2),
                    ]);
                    $service->recalculateStatus($invoice);
                }
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
                ? route('sales.invoices.edit', $payment->invoice_id)
                : route('sales.sales_payments.index');
        }

        return redirect()->to($redirectTo)
            ->with('toasts', [['type' => 'success', 'message' => "Payment {$payment->number} updated."]]);
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
                $payment->delete();

                if ($invoice) {
                    $invoice->update(['paid_amount' => round((float) $invoice->payments()->sum('amount'), 2)]);
                    $this->recalculateStatus($invoice);
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

        $html = view('sales.payments.pdf', compact('payment'))->render();

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

    private function validateData(Request $request): array
    {
        return $request->validate([
            'invoice_id' => ['nullable', 'integer', Rule::exists('sales_invoices', 'id')],
            'customer_id' => ['required', 'integer', Rule::exists('sales_customers', 'id')],
            'bank_account_id' => ['nullable', 'integer', Rule::exists('bank_accounts', 'id')],
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_date' => ['required', 'date'],
            'method' => ['required', Rule::in(SalesPayment::methodOptions())],
            'reference' => ['nullable', 'string', 'max:120'],
            'currency' => ['nullable', 'string', 'max:8'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
    }
}
