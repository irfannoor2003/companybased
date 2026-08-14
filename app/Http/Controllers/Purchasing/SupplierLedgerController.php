<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\DebitNote;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Support\ExportsCsv;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupplierLedgerController extends Controller
{
    use ExportsCsv;

    public function index(Request $request): View
    {
        $suppliers = Supplier::query()
            ->withCount(['purchaseInvoices', 'payments'])
            ->with([
                'purchaseInvoices' => fn ($q) => $q->whereIn('status', ['sent', 'partially_paid', 'overdue']),
                'payments',
                'debitNotes',
            ])
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->orderBy('company_name')
            ->get()
            ->map(fn (Supplier $supplier) => [
                'supplier' => $supplier,
                'balance' => $this->nativeBalance($supplier),
                'amount_base' => $this->baseBalance($supplier),
                'overdue' => $this->overdueTotal($supplier),
                'billed' => $supplier->purchaseInvoices->sum('total'),
                'paid' => $supplier->payments->sum('amount'),
            ]);

        $grandTotal = round($suppliers->sum(fn ($row) => $row['amount_base']), 2);

        return view('suppliers.supplier_ledger.index', compact('suppliers', 'grandTotal'));
    }

    private function nativeBalance(Supplier $supplier): float
    {
        $billed = (float) $supplier->purchaseInvoices->sum('total');
        $paid = (float) $supplier->payments->sum('amount');
        $credited = (float) $supplier->debitNotes->sum('applied_amount');

        return round($billed - $paid - $credited, 2);
    }

    /**
     * Outstanding payable expressed in the company's base currency. Mirrors
     * Supplier::balance() but converts each document (invoice, payment, debit
     * note) using its own snapshotted exchange_rate — never the live reference
     * rate, so historical reports stay accurate after a rate edit.
     */
    private function baseBalance(Supplier $supplier): float
    {
        $billed = $supplier->purchaseInvoices
            ->sum(fn (PurchaseInvoice $invoice) => to_base_currency($invoice->total, $invoice->exchange_rate));

        $paid = $supplier->payments
            ->sum(fn (SupplierPayment $payment) => to_base_currency($payment->amount, $payment->exchange_rate));

        $credited = $supplier->debitNotes
            ->sum(fn (DebitNote $note) => to_base_currency($note->applied_amount, $note->exchange_rate));

        return round((float) $billed - (float) $paid - (float) $credited, 2);
    }

    private function overdueTotal(Supplier $supplier): float
    {
        return (float) $supplier->purchaseInvoices
            ->filter(fn (PurchaseInvoice $i) => in_array($i->status, ['sent', 'partially_paid', 'overdue'])
                && $i->due_date < now()->toDateString())
            ->sum('total');
    }

    public function show(Supplier $supplier): View
    {
        $supplier->load([
            'purchaseInvoices' => fn ($q) => $q->with(['payments'])->orderBy('issue_date'),
            'payments' => fn ($q) => $q->with(['invoice'])->orderBy('payment_date'),
            'debitNotes' => fn ($q) => $q->orderBy('issue_date'),
        ]);

        $ledger = collect();

        foreach ($supplier->purchaseInvoices as $invoice) {
            $ledger->push([
                'date' => $invoice->issue_date,
                'type' => 'Invoice',
                'reference' => $invoice->number,
                'debit' => null,
                'credit' => $invoice->total,
                'url' => route('suppliers.purchase_invoices.edit', $invoice),
            ]);
        }

        foreach ($supplier->payments as $payment) {
            $ledger->push([
                'date' => $payment->payment_date,
                'type' => 'Payment',
                'reference' => $payment->invoice?->number ?? '—',
                'debit' => $payment->amount,
                'credit' => null,
                'url' => route('suppliers.supplier_payments.edit', $payment),
            ]);
        }

        foreach ($supplier->debitNotes as $note) {
            $ledger->push([
                'date' => $note->issue_date,
                'type' => 'Debit note',
                'reference' => $note->number,
                'debit' => $note->total,
                'credit' => null,
                'url' => route('suppliers.debit_notes.edit', $note),
            ]);
        }

        $ledger = $ledger->sortBy('date')->values();

        $running = 0.0;
        $ledger = $ledger->map(function (array $row) use (&$running) {
            $running += (float) ($row['credit'] ?? 0) - (float) ($row['debit'] ?? 0);

            return $row + ['balance' => round($running, 2)];
        });

        return view('suppliers.supplier_ledger.show', compact('supplier', 'ledger'));
    }

    public function export(Supplier $supplier): StreamedResponse
    {
        $rows = [];

        foreach ($supplier->purchaseInvoices()->orderBy('issue_date')->get() as $invoice) {
            $rows[] = [$invoice->issue_date->format('Y-m-d'), 'Invoice', $invoice->number, $invoice->total, null];
        }

        foreach ($supplier->payments()->orderBy('payment_date')->get() as $payment) {
            $rows[] = [$payment->payment_date->format('Y-m-d'), 'Payment', $payment->invoice?->number ?? '—', null, $payment->amount];
        }

        foreach ($supplier->debitNotes()->orderBy('issue_date')->get() as $note) {
            $rows[] = [$note->issue_date->format('Y-m-d'), 'Debit note', $note->number, null, $note->total];
        }

        usort($rows, fn ($a, $b) => strcmp($a[0], $b[0]));

        $running = 0.0;
        $ledger = [];
        foreach ($rows as $row) {
            $running += (float) ($row[3] ?? 0) - (float) ($row[4] ?? 0);
            $ledger[] = [$row[0], $row[1], $row[2], $row[3] ?? '', $row[4] ?? '', number_format($running, 2)];
        }

        return $this->streamCsv('supplier-ledger-'.$supplier->id.'-'.now()->format('Y-m-d').'.csv', ['Date', 'Type', 'Reference', 'Debit', 'Credit', 'Balance'], $ledger);
    }
}
