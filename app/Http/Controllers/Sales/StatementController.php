<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\SalesCreditNote;
use App\Models\SalesCustomer;
use App\Models\SalesInvoice;
use App\Models\SalesPayment;
use App\Support\ExportsCsv;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StatementController extends Controller
{
    use ExportsCsv;

    public function index(Request $request): View
    {
        $customers = SalesCustomer::query()
            ->withCount(['invoices', 'payments'])
            ->with([
                'invoices' => fn ($q) => $q->whereIn('status', ['sent', 'partially_paid', 'overdue']),
                'payments',
                'creditNotes',
            ])
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->orderBy('company_name')
            ->get()
            ->map(fn (SalesCustomer $customer) => [
                'customer' => $customer,
                'balance' => $this->nativeBalance($customer),
                'amount_base' => $this->baseBalance($customer),
                'overdue' => $this->overdueTotal($customer),
                'billed' => $customer->invoices->sum('total'),
                'paid' => $customer->payments->sum('amount'),
            ]);

        $grandTotal = round($customers->sum(fn ($row) => $row['amount_base']), 2);

        return view('sales.statements.index', compact('customers', 'grandTotal'));
    }

    private function nativeBalance(SalesCustomer $customer): float
    {
        $billed = (float) $customer->invoices->sum('total');
        $paid = (float) $customer->payments->sum('amount');
        $credited = (float) $customer->creditNotes->sum('applied_amount');

        return round($billed - $paid - $credited, 2);
    }

    /**
     * Outstanding balance expressed in the company's base currency. Mirrors
     * SalesCustomer::balance() but converts each document (invoice, payment,
     * credit note) using its own snapshotted exchange_rate — never the live
     * reference rate, so historical reports stay accurate after a rate edit.
     */
    private function baseBalance(SalesCustomer $customer): float
    {
        $billed = $customer->invoices
            ->sum(fn (SalesInvoice $invoice) => to_base_currency($invoice->total, $invoice->exchange_rate));

        $paid = $customer->payments
            ->sum(fn (SalesPayment $payment) => to_base_currency($payment->amount, $payment->exchange_rate));

        $credited = $customer->creditNotes
            ->sum(fn (SalesCreditNote $note) => to_base_currency($note->applied_amount, $note->exchange_rate));

        return round((float) $billed - (float) $paid - (float) $credited, 2);
    }

    private function overdueTotal(SalesCustomer $customer): float
    {
        return (float) $customer->invoices
            ->filter(fn (SalesInvoice $i) => in_array($i->status, ['sent', 'partially_paid', 'overdue'])
                && $i->due_date < now()->toDateString())
            ->sum('total');
    }

    public function show(SalesCustomer $customer): View
    {
        $customer->load([
            'invoices' => fn ($q) => $q->with(['payments'])->orderBy('issue_date'),
            'payments' => fn ($q) => $q->with(['invoice'])->orderBy('payment_date'),
            'creditNotes' => fn ($q) => $q->orderBy('issue_date'),
        ]);

        $ledger = collect();

        foreach ($customer->invoices as $invoice) {
            $ledger->push([
                'date' => $invoice->issue_date,
                'type' => 'Invoice',
                'reference' => $invoice->number,
                'debit' => $invoice->total,
                'credit' => null,
                'url' => route('sales.invoices.edit', $invoice),
            ]);
        }

        foreach ($customer->payments as $payment) {
            $ledger->push([
                'date' => $payment->payment_date,
                'type' => 'Payment',
                'reference' => $payment->invoice?->number ?? '—',
                'debit' => null,
                'credit' => $payment->amount,
                'url' => $payment->invoice ? route('sales.invoices.edit', $payment->invoice) : null,
            ]);
        }

        foreach ($customer->creditNotes as $note) {
            $ledger->push([
                'date' => $note->issue_date,
                'type' => 'Credit note',
                'reference' => $note->number,
                'debit' => null,
                'credit' => $note->total,
                'url' => route('sales.credit_notes.edit', $note),
            ]);
        }

        $ledger = $ledger->sortBy('date')->values();

        $running = 0.0;
        $ledger = $ledger->map(function (array $row) use (&$running) {
            $running += (float) ($row['debit'] ?? 0) - (float) ($row['credit'] ?? 0);

            return $row + ['balance' => round($running, 2)];
        });

        return view('sales.statements.show', compact('customer', 'ledger'));
    }

    public function export(SalesCustomer $customer): StreamedResponse
    {
        $rows = [];

        foreach ($customer->invoices()->orderBy('issue_date')->get() as $invoice) {
            $rows[] = [$invoice->issue_date->format('Y-m-d'), 'Invoice', $invoice->number, $invoice->total, null];
        }

        foreach ($customer->payments()->orderBy('payment_date')->get() as $payment) {
            $rows[] = [$payment->payment_date->format('Y-m-d'), 'Payment', $payment->invoice?->number ?? '—', null, $payment->amount];
        }

        foreach ($customer->creditNotes()->orderBy('issue_date')->get() as $note) {
            $rows[] = [$note->issue_date->format('Y-m-d'), 'Credit note', $note->number, null, $note->total];
        }

        usort($rows, fn ($a, $b) => strcmp($a[0], $b[0]));

        $running = 0.0;
        $ledger = [];
        foreach ($rows as $row) {
            $running += (float) ($row[3] ?? 0) - (float) ($row[4] ?? 0);
            $ledger[] = [$row[0], $row[1], $row[2], $row[3] ?? '', $row[4] ?? '', number_format($running, 2)];
        }

        return $this->streamCsv('statement-'.$customer->id.'-'.now()->format('Y-m-d').'.csv', ['Date', 'Type', 'Reference', 'Debit', 'Credit', 'Balance'], $ledger);
    }
}
