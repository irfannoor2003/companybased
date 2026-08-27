<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseStatusEvent;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Support\ExportsCsv;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function create(Request $request): View
    {
        $suppliers = Supplier::query()->orderBy('company_name')->get();
        $invoices = PurchaseInvoice::query()
            ->where('status', '!=', 'paid')
            ->orderBy('issue_date')
            ->get();

        $fromInvoice = null;
        if ($request->filled('invoice')) {
            $fromInvoice = PurchaseInvoice::query()->with(['supplier'])->findOrFail($request->invoice);
        }

        return view('suppliers.supplier_payments.create', compact('suppliers', 'invoices', 'fromInvoice'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

        if (! empty($data['invoice_id'])) {
            $invoice = PurchaseInvoice::findOrFail($data['invoice_id']);
            $remaining = $invoice->balance();

            if ((float) $data['amount'] > $remaining) {
                return back()->withInput()
                    ->with('toasts', [['type' => 'danger', 'message' => 'Payment amount ('.money($data['amount'], $invoice->currency).') exceeds the outstanding balance of '.money($remaining, $invoice->currency).'.']]);
            }
        }

        $payment = SupplierPayment::create([
            'number' => next_document_number('supplier_payment', 'SP', SupplierPayment::class),
            'invoice_id' => $data['invoice_id'] ?? null,
            'supplier_id' => $data['supplier_id'],
            'amount' => $data['amount'],
            'payment_date' => $data['payment_date'],
            'method' => $data['method'],
            'reference' => $data['reference'] ?? null,
            'currency' => $data['currency'] ?? null,
            'exchange_rate' => exchange_rate_for($data['currency'] ?? null),
            'notes' => $data['notes'] ?? null,
        ]);

        if ($payment->invoice_id) {
            $this->applyToInvoice($payment, $data['amount']);
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

        return view('suppliers.supplier_payments.edit', compact('payment', 'suppliers', 'invoices'));
    }

    public function update(Request $request, SupplierPayment $payment): RedirectResponse
    {
        $data = $this->validateData($request);

        $oldAmount = (float) $payment->amount;
        $newAmount = (float) $data['amount'];

        if (! empty($data['invoice_id']) && $newAmount !== $oldAmount) {
            $invoice = PurchaseInvoice::findOrFail($data['invoice_id']);
            $remaining = $invoice->balance() + $oldAmount;

            if ($newAmount > $remaining) {
                return back()->withInput()
                    ->with('toasts', [['type' => 'danger', 'message' => 'Payment amount ('.money($newAmount, $invoice->currency).') exceeds the outstanding balance of '.money($remaining, $invoice->currency).'.']]);
            }
        }

        $payment->update([
            'invoice_id' => $data['invoice_id'] ?? null,
            'supplier_id' => $data['supplier_id'],
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
                ? route('suppliers.purchase_invoices.edit', $payment->invoice_id)
                : route('suppliers.supplier_payments.index');
        }

        return redirect()->to($redirectTo)
            ->with('toasts', [['type' => 'success', 'message' => "Payment {$payment->number} updated."]]);
    }

    public function destroy(SupplierPayment $payment): RedirectResponse
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

        return redirect()->route('suppliers.supplier_payments.index')
            ->with('toasts', [['type' => 'success', 'message' => "Payment {$number} deleted."]]);
    }

    public function pdf(SupplierPayment $payment): StreamedResponse
    {
        $payment->load(['supplier', 'invoice']);

        $html = view('suppliers.supplier_payments.pdf', compact('payment'))->render();

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

    private function applyToInvoice(SupplierPayment $payment, mixed $amount): void
    {
        $invoice = $payment->invoice()->first();

        if (! $invoice) {
            return;
        }

        $invoice->update(['paid_amount' => round((float) $invoice->paid_amount + (float) $amount, 2)]);
        $this->recalculateStatus($invoice);
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
            'invoice_id' => ['nullable', 'integer', Rule::exists('purchase_invoices', 'id')],
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')],
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_date' => ['required', 'date'],
            'method' => ['required', Rule::in(SupplierPayment::methodOptions())],
            'reference' => ['nullable', 'string', 'max:120'],
            'currency' => ['nullable', 'string', 'max:8'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
    }
}
