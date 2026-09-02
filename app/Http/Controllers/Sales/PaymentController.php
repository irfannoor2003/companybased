<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\SalesInvoice;
use App\Models\SalesPayment;
use App\Models\SalesCustomer;
use App\Models\SalesStatusEvent;
use App\Support\ExportsCsv;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        if (! empty($data['invoice_id'])) {
            $invoice = SalesInvoice::findOrFail($data['invoice_id']);
            $remaining = $invoice->balance();

            if ((float) $data['amount'] > $remaining) {
                return back()->withInput()
                    ->with('toasts', [['type' => 'danger', 'message' => 'Payment amount ('.money($data['amount'], $invoice->currency).') exceeds the outstanding balance of '.money($remaining, $invoice->currency).'.']]);
            }
        }

        try {
            $payment = SalesPayment::create([
                'number' => next_document_number('sales_payment', 'RC', SalesPayment::class),
                'invoice_id' => $data['invoice_id'] ?? null,
                'customer_id' => $data['customer_id'],
                'bank_account_id' => $data['bank_account_id'] ?? null,
                'amount' => $data['amount'],
                'payment_date' => $data['payment_date'],
                'method' => $data['method'],
                'reference' => $data['reference'] ?? null,
                'currency' => $data['currency'] ?? null,
                'exchange_rate' => exchange_rate_for($data['currency'] ?? null),
                'notes' => $data['notes'] ?? null,
            ]);
        } catch (QueryException $e) {
            report($e);

            return back()->withInput()
                ->with('toasts', [['type' => 'danger', 'message' => 'Could not record the payment. Please try again — if the problem persists, contact support.']]);
        }

        if ($payment->invoice_id) {
            $this->applyToInvoice($payment, $data['amount']);
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

        $oldAmount = (float) $payment->amount;
        $newAmount = (float) $data['amount'];

        if (! empty($data['invoice_id']) && $newAmount !== $oldAmount) {
            $invoice = SalesInvoice::findOrFail($data['invoice_id']);
            $remaining = $invoice->balance() + $oldAmount;

            if ($newAmount > $remaining) {
                return back()->withInput()
                    ->with('toasts', [['type' => 'danger', 'message' => 'Payment amount ('.money($newAmount, $invoice->currency).') exceeds the outstanding balance of '.money($remaining, $invoice->currency).'.']]);
            }
        }

        $payment->update([
            'invoice_id' => $data['invoice_id'] ?? null,
            'customer_id' => $data['customer_id'],
            'bank_account_id' => $data['bank_account_id'] ?? null,
            'amount' => $data['amount'],
            'payment_date' => $data['payment_date'],
            'method' => $data['method'],
            'reference' => $data['reference'] ?? null,
            'currency' => $data['currency'] ?? null,
            'exchange_rate' => exchange_rate_for($data['currency'] ?? null),
            'notes' => $data['notes'] ?? null,
        ]);

        if ($oldAmount !== $newAmount) {
            if ($payment->invoice_id) {
                $invoice = $payment->invoice()->first();
                if ($invoice) {
                    $invoice->update(['paid_amount' => max(0, round($invoice->paid_amount - $oldAmount + $newAmount, 2))]);
                    $this->recalculateStatus($invoice);
                }
            }
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

        if ($payment->invoice_id) {
            $invoice = $payment->invoice()->first();
            if ($invoice) {
                $invoice->update(['paid_amount' => max(0, round((float) $invoice->paid_amount - (float) $payment->amount, 2))]);
                $this->recalculateStatus($invoice);
            }
        }

        $payment->delete();

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

    private function applyToInvoice(SalesPayment $payment, mixed $amount): void
    {
        $invoice = $payment->invoice()->first();

        if (! $invoice) {
            return;
        }

        $invoice->update(['paid_amount' => round((float) $invoice->paid_amount + (float) $amount, 2)]);
        $this->recalculateStatus($invoice);
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
