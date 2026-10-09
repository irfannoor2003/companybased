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
                // `paid` is included because the payments that settled those
                // invoices are still subtracted below. Leaving it out made every
                // fully paid customer read as being in credit by the value of
                // their invoices, which made the "Total outstanding" card report
                // a large negative number for a book of business fully settled.
                'invoices' => fn ($q) => $q->whereIn('status', ['sent', 'partially_paid', 'overdue', 'paid']),
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
                'opening' => $customer->openingBalance(),
            ]);

        $grandTotal = round($customers->sum(fn ($row) => $row['amount_base']), 2);

        return view('sales.statements.index', compact('customers', 'grandTotal'));
    }

    private function nativeBalance(SalesCustomer $customer): float
    {
        // Delegated to the model rather than recomputed. This method existed
        // alongside SalesCustomer::balance() as a second implementation of the
        // same formula, and they had already diverged — which is exactly the
        // shape of bug where the list, the detail page and the statement each
        // report a different number for one customer.
        return $customer->balance();
    }

    /**
     * Outstanding balance expressed in the company's base currency. Mirrors
     * SalesCustomer::balance() but converts each document (invoice, payment,
     * credit note) using its own snapshotted exchange_rate — never the live
     * reference rate, so historical reports stay accurate after a rate edit.
     */
    private function baseBalance(SalesCustomer $customer): float
    {
        $opening = to_base_currency($customer->openingBalance(), exchange_rate_for($customer->currency));

        $billed = $customer->invoices
            ->sum(fn (SalesInvoice $invoice) => to_base_currency($invoice->total, $invoice->exchange_rate));

        $paid = $customer->payments
            ->sum(fn (SalesPayment $payment) => to_base_currency($payment->amount, $payment->exchange_rate));

        $credited = $customer->creditNotes
            ->sum(fn (SalesCreditNote $note) => to_base_currency($note->applied_amount, $note->exchange_rate));

        return round($opening - (float) $paid - (float) $credited + (float) $billed, 2);
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

        // The opening balance is the first line of the ledger, dated so the
        // running balance starts where the previous system left off. Without it
        // every subsequent running balance is off by the opening amount.
        if ($customer->openingBalance() != 0.0) {
            $opening = $customer->openingBalance();

            $ledger->push([
                'date' => $customer->opening_balance_date?->copy()->startOfDay() ?? now()->startOfDay(),
                'type' => 'Opening balance',
                'reference' => 'Brought forward',
                'debit' => $opening > 0 ? $opening : null,
                'credit' => $opening < 0 ? abs($opening) : null,
                'url' => null,
            ]);
        }

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
                // applied_amount, not total: a credit note can be issued for more
                // than was applied against invoices, and crediting the full total
                // here made the running balance disagree with the closing balance
                // shown above the table, which is computed from applied_amount.
                'credit' => $note->applied_amount,
                'url' => route('sales.credit_notes.edit', $note),
            ]);
        }

        // The opening balance is set aside and prepended rather than sorted in
        // with the rest. It is by definition prior to every document on the
        // account, and it may carry a date inside the same period, in which case
        // a plain date sort would interleave it mid-table and the running
        // balance would start from zero.
        $opening = $ledger->firstWhere('type', 'Opening balance');

        $ledger = $ledger
            ->reject(fn (array $row): bool => $row['type'] === 'Opening balance')
            ->sortBy(fn (array $row): int => $row['date']?->getTimestamp() ?? 0)
            ->values();

        if ($opening !== null) {
            $ledger->prepend($opening);
        }

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

        // Same row set and the same ordering rules as show(), so the CSV and the
        // on-screen statement can never disagree.
        $opening = $customer->openingBalance();

        if ($opening != 0.0) {
            $rows[] = [
                ($customer->opening_balance_date ?? now())->format('Y-m-d'),
                'Opening balance',
                'Brought forward',
                $opening > 0 ? $opening : null,
                $opening < 0 ? abs($opening) : null,
            ];
        }

        foreach ($customer->invoices()->orderBy('issue_date')->get() as $invoice) {
            $rows[] = [$invoice->issue_date->format('Y-m-d'), 'Invoice', $invoice->number, $invoice->total, null];
        }

        foreach ($customer->payments()->orderBy('payment_date')->get() as $payment) {
            $rows[] = [$payment->payment_date->format('Y-m-d'), 'Payment', $payment->invoice?->number ?? '—', null, $payment->amount];
        }

        foreach ($customer->creditNotes()->orderBy('issue_date')->get() as $note) {
            $rows[] = [$note->issue_date->format('Y-m-d'), 'Credit note', $note->number, null, $note->applied_amount];
        }

        $isOpening = fn (array $row): bool => $row[1] === 'Opening balance';

        usort($rows, function (array $a, array $b) use ($isOpening): int {
            if ($isOpening($a) !== $isOpening($b)) {
                return $isOpening($a) ? -1 : 1;
            }

            return strcmp($a[0], $b[0]);
        });

        $running = 0.0;
        $ledger = [];
        foreach ($rows as $row) {
            $running += (float) ($row[3] ?? 0) - (float) ($row[4] ?? 0);
            $ledger[] = [$row[0], $row[1], $row[2], $row[3] ?? '', $row[4] ?? '', number_format($running, 2)];
        }

        return $this->streamCsv('statement-'.$customer->id.'-'.now()->format('Y-m-d').'.csv', ['Date', 'Type', 'Reference', 'Debit', 'Credit', 'Balance'], $ledger);
    }
}
