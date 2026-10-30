<?php

namespace App\Http\Controllers;

use App\Models\DebitNote;
use App\Models\SalesCreditNote;
use App\Models\SalesCustomer;
use App\Models\SalesDeliveryNote;
use App\Models\SalesInvoice;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The register reports of the Reports hub: customers, suppliers, credit notes,
 * debit notes and delivery challans.
 *
 * WHY ONE CONTROLLER
 *
 * These five reports are siblings -- each is a per-party or per-document
 * register with the same date filter, the same CSV export and the same table
 * treatment. Splitting them across the Sales and Purchasing namespaces (the way
 * the statements and ledgers they complement are) would scatter the one thing
 * they share: the arithmetic this file defines once and both sides use.
 *
 * WHY THE AGGREGATES REPRODUCE THE MODEL ARITHMETIC
 *
 * SalesCustomer::balance() and Supplier::balance() both carry a documented
 * history of being subtly wrong in two ways: `paid` invoices excluded (every
 * fully-paid party showed a credit equal to everything they had ever paid) and
 * multi-currency totals added raw (USD added to PKR). This report inherits the
 * corrected arithmetic rather than re-deriving it: live statuses only,
 * per-row currency conversion through the document's own snapshotted
 * `exchange_rate`, and no subtraction of `credit_applied` -- the payments that
 * money sits in have already been subtracted, so it would count twice.
 */
class RegistersReportController extends Controller
{
    public function index(): View
    {
        return view('reports.registers.index');
    }

    // ----------------------------------------------------------------------
    // Customers
    // ----------------------------------------------------------------------

    public function customers(Request $request): View
    {
        [$from, $to] = $this->range($request);

        $rows = $this->customerRows($from, $to);

        return view('reports.registers.customers', [
            'rows' => $rows,
            'totals' => $this->columnTotals($rows, ['invoiced', 'received', 'credited', 'balance']),
        ]);
    }

    public function customersExport(Request $request): Response
    {
        [$from, $to] = $this->range($request);

        $rows = array_map(fn (array $row) => [
            $row['code'] ?: '—',
            $row['name'],
            number_format($row['invoiced'], 2),
            number_format($row['received'], 2),
            number_format($row['credited'], 2),
            number_format($row['balance'], 2),
        ], $this->customerRows($from, $to));

        return $this->csv('customers-report-'.now()->format('Y-m-d').'.csv', [
            'Code', 'Customer', 'Invoiced', 'Payments received', 'Credit notes applied', 'Balance due',
        ], $rows);
    }

    /**
     * One row per customer. `balance` follows SalesCustomer::balance() exactly
     * -- opening balance + billed - paid - credited -- with the period filter
     * applied to each of the three activity figures independently, because a
     * payment and the invoice it settles rarely carry the same date.
     *
     * @return array<int, array<string, float|string>>
     */
    private function customerRows(?string $from, ?string $to): array
    {
        $liveStatuses = ['sent', 'partially_paid', 'overdue', 'paid'];

        $billed = $this->sumsByParty('sales_invoices', 'customer_id', 'total', 'issue_date', $from, $to, ['status' => $liveStatuses]);
        $paid = $this->sumsByParty('sales_payments', 'customer_id', 'amount', 'payment_date', $from, $to);
        $credited = $this->sumsByParty('sales_credit_notes', 'customer_id', 'applied_amount', 'issue_date', $from, $to);

        return SalesCustomer::query()
            ->orderBy('company_name')
            ->get()
            ->map(function (SalesCustomer $customer) use ($billed, $paid, $credited): array {
                $invoiced = $billed[$customer->id] ?? 0.0;
                $received = $paid[$customer->id] ?? 0.0;
                $thisCredited = $credited[$customer->id] ?? 0.0;

                return [
                    'id' => $customer->id,
                    'code' => (string) $customer->short_code,
                    'name' => (string) $customer->company_name,
                    'invoiced' => round($invoiced, 2),
                    'received' => round($received, 2),
                    'credited' => round($thisCredited, 2),
                    'opening' => (float) $customer->opening_balance,
                    // The opening balance is part of the figure, not beside it:
                    // it is what the customer already owed before this system
                    // started counting. See SalesCustomer::balance().
                    'balance' => round((float) $customer->opening_balance + $invoiced - $received - $thisCredited, 2),
                ];
            })
            ->all();
    }

    // ----------------------------------------------------------------------
    // Suppliers
    // ----------------------------------------------------------------------

    public function suppliers(Request $request): View
    {
        [$from, $to] = $this->range($request);

        $rows = $this->supplierRows($from, $to);

        return view('reports.registers.suppliers', [
            'rows' => $rows,
            'totals' => $this->columnTotals($rows, ['invoiced', 'paid_out', 'credited', 'balance']),
        ]);
    }

    public function suppliersExport(Request $request): Response
    {
        [$from, $to] = $this->range($request);

        $rows = array_map(fn (array $row) => [
            $row['code'] ?: '—',
            $row['name'],
            number_format($row['invoiced'], 2),
            number_format($row['paid_out'], 2),
            number_format($row['credited'], 2),
            number_format($row['balance'], 2),
        ], $this->supplierRows($from, $to));

        return $this->csv('suppliers-report-'.now()->format('Y-m-d').'.csv', [
            'Code', 'Supplier', 'Invoiced', 'Payments made', 'Debit notes applied', 'Balance due',
        ], $rows);
    }

    /**
     * One row per supplier, mirroring Supplier::balance(): billed (live
     * purchase invoices, per-row converted) minus payments made minus debit
     * notes actually applied. Suppliers carry no opening balance in the
     * schema, so unlike customers there is nothing to seed the figure with.
     *
     * @return array<int, array<string, float|string>>
     */
    private function supplierRows(?string $from, ?string $to): array
    {
        $liveStatuses = ['sent', 'partially_paid', 'overdue', 'paid'];

        $billed = $this->sumsByParty('purchase_invoices', 'supplier_id', 'total', 'issue_date', $from, $to, ['status' => $liveStatuses]);
        $paid = $this->sumsByParty('supplier_payments', 'supplier_id', 'amount', 'payment_date', $from, $to);
        $credited = $this->sumsByParty('debit_notes', 'supplier_id', 'applied_amount', 'issue_date', $from, $to);

        return Supplier::query()
            ->orderBy('company_name')
            ->get()
            ->map(function (Supplier $supplier) use ($billed, $paid, $credited): array {
                $invoiced = $billed[$supplier->id] ?? 0.0;
                $paidOut = $paid[$supplier->id] ?? 0.0;
                $thisCredited = $credited[$supplier->id] ?? 0.0;

                return [
                    'code' => (string) $supplier->short_code,
                    'name' => (string) $supplier->company_name,
                    'invoiced' => round($invoiced, 2),
                    'paid_out' => round($paidOut, 2),
                    'credited' => round($thisCredited, 2),
                    'balance' => round($invoiced - $paidOut - $thisCredited, 2),
                ];
            })
            ->all();
    }

    // ----------------------------------------------------------------------
    // Credit notes
    // ----------------------------------------------------------------------

    public function creditNotes(Request $request): View
    {
        [$from, $to] = $this->range($request);

        $query = SalesCreditNote::query()->with('customer')
            ->when($from !== null, fn ($q) => $q->whereDate('issue_date', '>=', $from))
            ->when($to !== null, fn ($q) => $q->whereDate('issue_date', '<=', $to))
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->customer_id))
            ->orderByDesc('issue_date')->orderByDesc('number');

        $perPage = 30;
        $rows = $query->get();

        $customers = SalesCustomer::orderBy('company_name')->get(['id', 'company_name']);

        return view('reports.registers.credit_notes', [
            'rows' => $rows,
            'unappliedTotal' => round($rows->sum(fn (SalesCreditNote $note) => $note->total - $note->applied_amount), 2),
            'total' => round($rows->sum('total'), 2),
            'appliedTotal' => round($rows->sum('applied_amount'), 2),
            'customers' => $customers,
        ]);
    }

    public function creditNotesExport(Request $request): Response
    {
        [$from, $to] = $this->range($request);

        $notes = SalesCreditNote::query()->with('customer')
            ->when($from !== null, fn ($q) => $q->whereDate('issue_date', '>=', $from))
            ->when($to !== null, fn ($q) => $q->whereDate('issue_date', '<=', $to))
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->customer_id))
            ->orderByDesc('issue_date')->orderByDesc('number')
            ->get();

        $rows = $notes->map(fn (SalesCreditNote $note) => [
            $note->number,
            $note->issue_date,
            $note->customer?->company_name ?? '—',
            $note->reason ?: '—',
            $note->currency,
            number_format($note->total, 2),
            number_format($note->applied_amount, 2),
            number_format($note->total - $note->applied_amount, 2),
        ])->all();

        return $this->csv('credit-notes-report-'.now()->format('Y-m-d').'.csv', [
            'Number', 'Date', 'Customer', 'Reason', 'Currency', 'Total', 'Applied', 'Unapplied',
        ], $rows);
    }

    // ----------------------------------------------------------------------
    // Debit notes
    // ----------------------------------------------------------------------

    public function debitNotes(Request $request): View
    {
        [$from, $to] = $this->range($request);

        $rows = DebitNote::query()->with(['supplier', 'items'])
            ->when($from !== null, fn ($q) => $q->whereDate('issue_date', '>=', $from))
            ->when($to !== null, fn ($q) => $q->whereDate('issue_date', '<=', $to))
            ->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', $request->supplier_id))
            ->orderByDesc('issue_date')->orderByDesc('number')
            ->get();

        $suppliers = Supplier::orderBy('company_name')->get(['id', 'company_name']);

        return view('reports.registers.debit_notes', [
            'rows' => $rows,
            'total' => round($rows->sum('total'), 2),
            'appliedTotal' => round($rows->sum('applied_amount'), 2),
            'unappliedTotal' => round($rows->sum(fn (DebitNote $note) => $note->total - $note->applied_amount), 2),
            'suppliers' => $suppliers,
        ]);
    }

    public function debitNotesExport(Request $request): Response
    {
        [$from, $to] = $this->range($request);

        $notes = DebitNote::query()->with('supplier')
            ->when($from !== null, fn ($q) => $q->whereDate('issue_date', '>=', $from))
            ->when($to !== null, fn ($q) => $q->whereDate('issue_date', '<=', $to))
            ->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', $request->supplier_id))
            ->orderByDesc('issue_date')->orderByDesc('number')
            ->get();

        $rows = $notes->map(fn (DebitNote $note) => [
            $note->number,
            $note->issue_date,
            $note->supplier?->company_name ?? '—',
            $note->reason ?: '—',
            $note->currency,
            number_format($note->total, 2),
            number_format($note->applied_amount, 2),
            number_format($note->total - $note->applied_amount, 2),
        ])->all();

        return $this->csv('debit-notes-report-'.now()->format('Y-m-d').'.csv', [
            'Number', 'Date', 'Supplier', 'Reason', 'Currency', 'Total', 'Applied', 'Unapplied',
        ], $rows);
    }

    // ----------------------------------------------------------------------
    // Delivery challans
    // ----------------------------------------------------------------------

    public function deliveryChallans(Request $request): View
    {
        [$from, $to] = $this->range($request);

        $query = SalesDeliveryNote::query()->with('customer')->withCount('items')
            ->when($from !== null, fn ($q) => $q->whereDate('issue_date', '>=', $from))
            ->when($to !== null, fn ($q) => $q->whereDate('issue_date', '<=', $to))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->customer_id))
            ->orderByDesc('issue_date')->orderByDesc('number');

        $rows = $query->get();

        // Quantities per challan, in one query, so the "Qty shipped" column is
        // the shipped figure rather than the number of item lines.
        $quantities = DB::table('sales_delivery_note_items')
            ->join('sales_delivery_notes', 'sales_delivery_note_items.delivery_note_id', '=', 'sales_delivery_notes.id')
            ->whereIn('sales_delivery_note_items.delivery_note_id', $rows->pluck('id'))
            ->selectRaw('delivery_note_id, SUM(qty) AS qty')
            ->groupBy('delivery_note_id')
            ->pluck('qty', 'delivery_note_id');

        $statuses = SalesDeliveryNote::query()->distinct()->orderBy('status')->pluck('status')->all();

        $customers = SalesCustomer::orderBy('company_name')->get(['id', 'company_name']);

        return view('reports.registers.delivery_challans', [
            'rows' => $rows,
            'quantities' => $quantities,
            'statuses' => $statuses,
            'customers' => $customers,
        ]);
    }

    public function deliveryChallansExport(Request $request): Response
    {
        [$from, $to] = $this->range($request);

        $quantities = DB::table('sales_delivery_note_items')
            ->join('sales_delivery_notes', 'sales_delivery_note_items.delivery_note_id', '=', 'sales_delivery_notes.id')
            ->when($from !== null, fn ($q) => $q->whereDate('sales_delivery_notes.issue_date', '>=', $from))
            ->when($to !== null, fn ($q) => $q->whereDate('sales_delivery_notes.issue_date', '<=', $to))
            ->when($request->filled('status'), fn ($q) => $q->where('sales_delivery_notes.status', $request->status))
            ->when($request->filled('customer_id'), fn ($q) => $q->where('sales_delivery_notes.customer_id', $request->customer_id))
            ->selectRaw('delivery_note_id, SUM(qty) AS qty')
            ->groupBy('delivery_note_id')
            ->pluck('qty', 'delivery_note_id');

        $rows = SalesDeliveryNote::query()->with('customer')->withCount('items')
            ->when($from !== null, fn ($q) => $q->whereDate('issue_date', '>=', $from))
            ->when($to !== null, fn ($q) => $q->whereDate('issue_date', '<=', $to))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->customer_id))
            ->orderByDesc('issue_date')->orderByDesc('number')
            ->get()
            ->map(fn (SalesDeliveryNote $note) => [
                $note->number,
                $note->issue_date,
                $note->customer?->company_name ?? '—',
                $note->order_id > 0 ? '#'.$note->order_id : '—',
                $note->carrier ?: '—',
                $note->tracking_number ?: '—',
                $note->items_count,
                $quantities[ $note->id ] ?? 0,
                $note->status,
            ])->all();

        return $this->csv('delivery-challans-report-'.now()->format('Y-m-d').'.csv', [
            'Number', 'Date', 'Customer', 'Order', 'Carrier', 'Tracking #', 'Item lines', 'Qty shipped', 'Status',
        ], $rows);
    }

    // ----------------------------------------------------------------------
    // Shared helpers
    // ----------------------------------------------------------------------

    /**
     * The from/to filter, normalised. A malformed request (from after to, or a
     * non-date string) degrades to "no filter on that end" rather than lying
     * with an empty result or throwing.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function range(Request $request): array
    {
        $from = $request->date('from')?->toDateString();
        $to = $request->date('to')?->toDateString();

        if ($from !== null && $to !== null && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to];
    }

    /**
     * Sum an amount column per party, converted to base currency per row, over
     * an optional date window.
     *
     * Every SUM carries the "per-row COALESCE(exchange_rate, 1)" treatment the
     * party models use: a document saved without a rate counts at face value
     * rather than dropping out of the figure, and two currencies are never
     * added raw.
     *
     * @param  array<string, array<int, string>>  $wheres  column => allowed values
     * @return array<int, float>  party id => base-currency total
     */
    private function sumsByParty(string $table, string $partyColumn, string $amountColumn, string $dateColumn, ?string $from, ?string $to, array $wheres = []): array
    {
        $query = DB::table($table)->whereNull("{$table}.deleted_at");

        foreach ($wheres as $column => $values) {
            $query->whereIn($column, $values);
        }

        if ($from !== null) {
            $query->whereDate($dateColumn, '>=', $from);
        }

        if ($to !== null) {
            $query->whereDate($dateColumn, '<=', $to);
        }

        return $query
            ->selectRaw("{$partyColumn} AS party, COALESCE(SUM({$amountColumn} * COALESCE(exchange_rate, 1)), 0) AS total")
            ->groupBy($partyColumn)
            ->pluck('total', 'party')
            ->map(fn ($total) => (float) $total)
            ->all();
    }

    /**
     * Grand totals for the report footer, for the named money columns only.
     *
     * @param  array<int, non-empty-string>  $moneyColumns
     * @return array<string, float>
     */
    private function columnTotals(array $rows, array $moneyColumns): array
    {
        $totals = [];

        foreach ($moneyColumns as $column) {
            $totals[$column] = round(array_sum(array_column($rows, $column)), 2);
        }

        return $totals;
    }

    /**
     * The CSV response all the register exports come out as.
     *
     * `fputcsv`, not `implode(',')`: a company trading as "Acme, Ltd" must not
     * shift every following column by one when opened in a spreadsheet.
     *
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    private function csv(string $filename, array $headers, array $rows): Response
    {
        $stream = fopen('php://temp', 'r+');

        fputcsv($stream, $headers);

        foreach ($rows as $row) {
            fputcsv($stream, $row);
        }

        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
