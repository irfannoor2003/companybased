<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\DebitNote;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Support\DocumentData;
use App\Support\ExportsCsv;
use App\Support\PdfLayout;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupplierLedgerController extends Controller
{
    use ExportsCsv;

    public function index(Request $request): View
    {
        $suppliers = Supplier::query()
            ->withCount(['purchaseInvoices', 'payments'])
            ->with([
                // `paid` included, matching Supplier::balance(). Excluding it
                // here while still subtracting the payments left every settled
                // supplier showing a negative payable.
                'purchaseInvoices' => fn ($q) => $q->whereIn('status', ['sent', 'partially_paid', 'overdue', 'paid']),
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
        // Delegated to the model. A second implementation of the same formula is
        // how the ledger index and the supplier page came to disagree.
        return $supplier->balance();
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
        return view('suppliers.supplier_ledger.show', [
            'supplier' => $supplier,
            'ledger' => $this->ledgerFor($supplier),
        ]);
    }

    /**
     * The ledger as rows, shared by the screen and the printed statement so the
     * two can never disagree.
     */
    private function ledgerFor(Supplier $supplier): Collection
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

        return $ledger;
    }

    /**
     * The supplier ledger printed in the company's invoice template, the same
     * treatment the customer statement gets.
     */
    public function pdf(Supplier $supplier): Response
    {
        $this->preparePdf();

        $ledger = $this->ledgerFor($supplier);

        $totals = [
            'opening' => 0.0,
            'debited' => round((float) $ledger->sum('debit'), 2),
            'credited' => round((float) $ledger->sum('credit'), 2),
            'closing' => round((float) ($ledger->last()['balance'] ?? 0), 2),
        ];

        $payload = [
            'doc' => $supplier,
            'title' => 'Supplier Ledger Statement',
            'number' => 'LEDG-'.str_pad((string) $supplier->id, 5, '0', STR_PAD_LEFT).'-'.now()->format('Ymd'),
            'customerName' => $supplier->company_name,
            'billTo' => [
                'heading' => 'Supplier',
                'name' => (string) $supplier->company_name,
                'contact' => (string) ($supplier->contact_name ?? ''),
                'address' => trim(implode("\n", array_filter([
                    (string) ($supplier->address ?? ''),
                    trim(((string) ($supplier->city ?? '')).' '.((string) ($supplier->country ?? ''))),
                ]))),
                'code' => (string) ($supplier->short_code ?? ''),
                'tax' => (string) ($supplier->tax_number ?? ''),
            ],
            'ledger' => $ledger
                ->map(fn (array $row) => collect($row)->except('url')->all())
                ->values(),
            'totals' => $totals,
            'currency' => $this->ledgerCurrency($supplier),
            'asOf' => now(),
            'layout' => PdfLayout::forDocumentType('invoice'),
            'notes' => null,
        ];

        $html = view('documents.statement', DocumentData::printable($payload))->render();

        return Pdf::loadHTML($html)
            ->setPaper('a4', 'portrait')
            ->setOptions(pdf_options())
            ->stream('supplier-ledger-'.$supplier->id.'-'.now()->format('Y-m-d').'.pdf');
    }

    /**
     * One currency for the printed page. Documents on a supplier ledger can
     * carry their own currency, but the on-screen ledger already reports every
     * figure side by side and the CSV does the same, so the statement prints
     * what the rest of the ledger prints rather than inventing a conversion.
     */
    private function ledgerCurrency(Supplier $supplier): string
    {
        return (string) ($supplier->currency ?: (settings('company.currency') ?: 'USD'));
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
