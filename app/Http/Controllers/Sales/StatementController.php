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
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->orderBy('company_name')
            ->get()
            ->map(fn (SalesCustomer $customer) => [
                'customer' => $customer,
                'balance' => $customer->balance(),
                'overdue' => (float) $customer->invoices()
                    ->whereIn('status', ['sent', 'partially_paid', 'overdue'])
                    ->whereDate('due_date', '<', now()->toDateString())
                    ->sum('total'),
            ]);

        $grandTotal = round($customers->sum(fn ($row) => $row['balance']), 2);

        return view('sales.statements.index', compact('customers', 'grandTotal'));
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
                'url' => route('sales.invoices.edit', $payment->invoice),
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
