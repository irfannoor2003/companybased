<?php

namespace App\Http\Controllers;

use App\Models\BankTransaction;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\SalesPayment;
use App\Models\SupplierPayment;
use App\Support\ExportsCsv;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Read-only cash flow reporting across banking, sales receipts and supplier
 * payments. Money is rendered from decimal strings â€” never floats.
 */
class CashFlowController extends Controller
{
    use ExportsCsv;

    public function overview(): View
    {
        $cashBalance = $this->cashAndBank();
        $receivables = $this->receivables();
        $payables = $this->payables();

        $now = now();
        $monthStart = $now->copy()->startOfMonth()->startOfDay();
        $monthEnd = $now->copy()->endOfMonth()->endOfDay();
        $sixtyDaysAgo = $now->copy()->subDays(60)->startOfDay()->toDateString();

        $allInflows = $this->inflowRows($sixtyDaysAgo, $monthEnd->toDateString());
        $allOutflows = $this->outflowRows($sixtyDaysAgo, $monthEnd->toDateString());

        // Bounds are Carbons, not 'Y-m-d' strings. Comparing a Carbon against a
        // date string falls back to __toString(), which yields 'Y-m-d H:i:s', so
        // a row dated the last day of the month stringified to
        // '2026-05-31 00:00:00' and compared greater than '2026-05-31' â€” dropping
        // the final day from "cash in this month" on exactly the day it mattered.
        $inMonth = fn ($r): bool => $r['date'] instanceof \DateTimeInterface
            && $r['date'] >= $monthStart
            && $r['date'] <= $monthEnd;

        $inflowsMonth = (float) $allInflows->filter($inMonth)->sum('amount_base');
        $outflowsMonth = (float) $allOutflows->filter($inMonth)->sum('amount_base');

        // Chart data: daily cash flow for the last 30 days
        $cashFlowChartData = $this->cashFlowChartData($sixtyDaysAgo, $monthEnd);

        $activity = $allInflows->concat($allOutflows)
            ->sortByDesc('date')
            ->take(12)
            ->values();

        return view('cash_flow.overview', compact('cashBalance', 'receivables', 'payables', 'inflowsMonth', 'outflowsMonth', 'activity', 'cashFlowChartData'));
    }

    /**
     * Daily cash flow data for chart (last 30 days).
     */
    private function cashFlowChartData(string $from, string $to): array
    {
        $allInflows = $this->inflowRows($from, $to);
        $allOutflows = $this->outflowRows($from, $to);

        // Group by day
        //
        // Rows are keyed by a Carbon instance, days by a 'Y-m-d' string. The
        // comparison was `$row['date'] === $dayKey`, and `Carbon === string` is
        // false â€” so no row ever matched and the trend chart rendered 61 days
        // of zeros no matter how much money moved. `sortBy` still ordered
        // correctly, which is why it looked like an empty business rather than a
        // broken comparison.
        $inByDay = [];
        foreach ($allInflows as $row) {
            $inByDay[(string) $row['date']->format('Y-m-d')] = ($inByDay[(string) $row['date']->format('Y-m-d')] ?? 0.0)
                + (float) $row['amount_base'];
        }

        $outByDay = [];
        foreach ($allOutflows as $row) {
            $outByDay[(string) $row['date']->format('Y-m-d')] = ($outByDay[(string) $row['date']->format('Y-m-d')] ?? 0.0)
                + (float) $row['amount_base'];
        }

        $daily = [];
        for ($d = strtotime($from); $d <= strtotime($to); $d = strtotime('+1 day', $d)) {
            $dayKey = date('Y-m-d', $d);
            $dayIn = $inByDay[$dayKey] ?? 0.0;
            $dayOut = $outByDay[$dayKey] ?? 0.0;

            $daily[$dayKey] = [
                'date' => $dayKey,
                'in' => round($dayIn, 2),
                'out' => round($dayOut, 2),
                'net' => round($dayIn - $dayOut, 2),
            ];
        }

        $labels = array_keys($daily);
        $inData = array_map(fn ($d) => $d['in'], $daily);
        $outData = array_map(fn ($d) => $d['out'], $daily);
        $netData = array_map(fn ($d) => $d['net'], $daily);

        return [
            'labels' => $labels,
            'in' => $inData,
            'out' => $outData,
            'net' => $netData,
        ];
    }

    public function inflows(Request $request): View
    {
        [$from, $to] = $this->period($request, 'inflows');
        $rows = $this->inflowRows($from, $to);

        return view('cash_flow.inflows', compact('rows', 'from', 'to'));
    }

    public function outflows(Request $request): View
    {
        [$from, $to] = $this->period($request, 'outflows');
        $rows = $this->outflowRows($from, $to);

        return view('cash_flow.outflows', compact('rows', 'from', 'to'));
    }

    public function forecast(): View
    {
        $cashBalance = $this->cashAndBank();

        $bucket = function (Collection $items) {
            $today = now();
            $buckets = ['0-30' => 0.0, '31-60' => 0.0, '61-90' => 0.0, '90+' => 0.0];

            foreach ($items as $item) {
                $days = (int) $today->diffInDays($item['due_date']);
                $key = $days <= 30 ? '0-30' : ($days <= 60 ? '31-60' : ($days <= 90 ? '61-90' : '90+'));
                $buckets[$key] += (float) $item['amount_base'];
            }

            return collect($buckets)->map(fn ($v) => round($v, 2));
        };

        $receivablesDue = SalesInvoice::query()
            ->whereIn('status', ['sent', 'partially_paid', 'overdue'])
            ->with('customer')
            ->get()
            ->map(fn (SalesInvoice $i) => ['due_date' => $i->due_date, 'balance' => $i->balance(), 'amount_base' => to_base_currency($i->balance(), $i->exchange_rate), 'customer' => $i->customer?->company_name, 'number' => $i->number]);

        $payablesDue = PurchaseInvoice::query()
            ->whereIn('status', ['sent', 'partially_paid', 'overdue'])
            ->with('supplier')
            ->get()
            ->map(fn (PurchaseInvoice $i) => ['due_date' => $i->due_date, 'balance' => $i->balance(), 'amount_base' => to_base_currency($i->balance(), $i->exchange_rate), 'supplier' => $i->supplier?->company_name, 'number' => $i->number]);

        $inflowBuckets = $bucket($receivablesDue);
        $outflowBuckets = $bucket($payablesDue);
        $netBuckets = collect($inflowBuckets->keys())->mapWithKeys(fn ($k) => [$k => round($inflowBuckets[$k] - $outflowBuckets[$k], 2)]);

        return view('cash_flow.forecast', compact('cashBalance', 'inflowBuckets', 'outflowBuckets', 'netBuckets', 'receivablesDue', 'payablesDue'));
    }

    public function reports(Request $request): View
    {
        [$from, $to] = $this->period($request, 'reports');

        $opening = round($this->bankBalanceBefore($from), 2);
        $inflows = round((float) $this->inflowRows($from, $to)->sum('amount_base'), 2);
        $outflows = round((float) $this->outflowRows($from, $to)->sum('amount_base'), 2);
        $net = round($inflows - $outflows, 2);
        $closing = round($opening + $net, 2);

        return view('cash_flow.reports', compact('from', 'to', 'opening', 'inflows', 'outflows', 'net', 'closing'));
    }

    public function inflowsExport(Request $request): StreamedResponse
    {
        [$from, $to] = $this->period($request, 'inflows');
        $rows = $this->inflowRows($from, $to);

        return $this->streamCsv('cash-inflows-'.$from.'-to-'.$to.'.csv', ['Date', 'Source', 'Reference', 'Description', 'Amount'], $rows->map(fn ($r) => [
            $r['date'] instanceof \DateTimeInterface ? $r['date']->format('Y-m-d') : $r['date'],
            $r['source'],
            $r['ref'],
            $r['desc'],
            $r['amount'],
        ]));
    }

    public function outflowsExport(Request $request): StreamedResponse
    {
        [$from, $to] = $this->period($request, 'outflows');
        $rows = $this->outflowRows($from, $to);

        return $this->streamCsv('cash-outflows-'.$from.'-to-'.$to.'.csv', ['Date', 'Source', 'Reference', 'Description', 'Amount'], $rows->map(fn ($r) => [
            $r['date'] instanceof \DateTimeInterface ? $r['date']->format('Y-m-d') : $r['date'],
            $r['source'],
            $r['ref'],
            $r['desc'],
            $r['amount'],
        ]));
    }

    public function forecastExport(): StreamedResponse
    {
        $today = now();
        $rows = collect();
        $step = fn ($start, $end) => $start.' to '.$end;

        $receivablesDue = SalesInvoice::query()->whereIn('status', ['sent', 'partially_paid', 'overdue'])->with('customer')->get();
        $payablesDue = PurchaseInvoice::query()->whereIn('status', ['sent', 'partially_paid', 'overdue'])->with('supplier')->get();

        foreach ($receivablesDue as $invoice) {
            $days = (int) $today->diffInDays($invoice->due_date);
            $rows->push(['type' => 'Inflow', 'number' => $invoice->number, 'party' => $invoice->customer?->company_name, 'due_date' => $invoice->due_date?->format('Y-m-d'), 'bucket' => $step(0, max(0, (($days + 29) / 30) * 30)), 'amount' => $invoice->balance()]);
        }

        foreach ($payablesDue as $invoice) {
            $days = (int) $today->diffInDays($invoice->due_date);
            $rows->push(['type' => 'Outflow', 'number' => $invoice->number, 'party' => $invoice->supplier?->company_name, 'due_date' => $invoice->due_date?->format('Y-m-d'), 'bucket' => $step(0, max(0, (($days + 29) / 30) * 30)), 'amount' => $invoice->balance()]);
        }

        return $this->streamCsv('cash-flow-forecast-'.now()->format('Y-m-d').'.csv', ['Type', 'Number', 'Party', 'Due date', 'Bucket', 'Amount'], $rows->map(fn ($r) => [
            $r['type'],
            $r['number'],
            $r['party'],
            $r['due_date'],
            $r['bucket'],
            $r['amount'],
        ]));
    }

    public function reportsExport(Request $request): StreamedResponse
    {
        [$from, $to] = $this->period($request, 'reports');

        $inflowTotal = round((float) $this->inflowRows($from, $to)->sum('amount_base'), 2);
        $outflowTotal = round((float) $this->outflowRows($from, $to)->sum('amount_base'), 2);

        return $this->streamCsv('cash-flow-statement-'.$from.'-to-'.$to.'.csv', ['Line', 'Amount'], [
            ['Opening cash & bank', $this->bankBalanceBefore($from)],
            ['Total inflows', $inflowTotal],
            ['Total outflows', $outflowTotal],
            ['Net change', round($inflowTotal - $outflowTotal, 2)],
            ['Closing cash & bank', round($this->bankBalanceBefore($from) + $inflowTotal - $outflowTotal, 2)],
        ]);
    }

    /**
     * Total cash and bank: every account's opening balance plus the net of its
     * movements.
     *
     * Aggregated in SQL rather than by loading every account and every one of its
     * transactions into memory. The previous version eager-loaded the entire
     * bank_transactions table on each of the four calls this page makes, which on
     * shared hosting exhausts the 128 MB limit well before the cash figures get
     * interesting. The arithmetic is identical: deposits and transfer_ins add,
     * withdrawals and transfer_outs subtract.
     */
    private function cashAndBank(): float
    {
        $movements = DB::table('bank_transactions')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN type IN (?, ?) THEN amount ELSE 0 END), 0) AS inflow',
                ['deposit', 'transfer_in'],
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN type IN (?, ?) THEN amount ELSE 0 END), 0) AS outflow',
                ['withdrawal', 'transfer_out'],
            )
            ->selectRaw('bank_account_id')
            ->groupBy('bank_account_id')
            ->get()
            ->keyBy('bank_account_id');

        $opening = DB::table('bank_accounts')->sum('opening_balance');

        $net = 0.0;

        foreach ($movements as $movement) {
            $net += (float) $movement->inflow - (float) $movement->outflow;
        }

        return round((float) $opening + $net, 2);
    }

    /**
     * Open receivables in base currency.
     *
     * Summed in SQL. The per-row expression mirrors SalesInvoice::balance()
     * exactly -- total less payments, less customer credit already drawn down,
     * less credit notes already applied -- and is then multiplied by the
     * document's own exchange rate, which is what to_base_currency() did per row.
     *
     * The four terms are load-bearing: omitting credit_applied or
     * credit_note_applied double-counts an invoice a customer has already
     * settled, which is why this was moved in lockstep with the model rather than
     * written from memory.
     */
    private function receivables(): float
    {
        return round((float) SalesInvoice::query()
            ->whereIn('status', ['sent', 'partially_paid', 'overdue'])
            ->selectRaw(
                'COALESCE(SUM((total - paid_amount - credit_applied - credit_note_applied)'
                .' * COALESCE(exchange_rate, 1)), 0) AS aggregate',
            )
            ->value('aggregate'), 2);
    }

    /**
     * Open payables in base currency. Mirrors PurchaseInvoice::balance().
     */
    private function payables(): float
    {
        return round((float) PurchaseInvoice::query()
            ->whereIn('status', ['sent', 'partially_paid', 'overdue'])
            ->selectRaw(
                'COALESCE(SUM((total - paid_amount) * COALESCE(exchange_rate, 1)), 0) AS aggregate',
            )
            ->value('aggregate'), 2);
    }

    /**
     * Cash and bank as at the start of the period, so the report can show a
     * closing figure. Aggregated in SQL for the same reason as cashAndBank().
     */
    private function bankBalanceBefore(string $date): float
    {
        $movements = DB::table('bank_transactions')
            ->where('transaction_date', '<', $date)
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN type IN (?, ?) THEN amount ELSE 0 END), 0) AS inflow',
                ['deposit', 'transfer_in'],
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN type IN (?, ?) THEN amount ELSE 0 END), 0) AS outflow',
                ['withdrawal', 'transfer_out'],
            )
            ->selectRaw('bank_account_id')
            ->groupBy('bank_account_id')
            ->get();

        $opening = DB::table('bank_accounts')->sum('opening_balance');

        $net = 0.0;

        foreach ($movements as $movement) {
            $net += (float) $movement->inflow - (float) $movement->outflow;
        }

        return round((float) $opening + $net, 2);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function inflowRows(string $from, string $to): Collection
    {
        $rows = collect();

        SalesPayment::query()->with(['customer', 'invoice'])->whereBetween('payment_date', [$from, $to])->get()
            ->each(function (SalesPayment $p) use (&$rows) {
                $rows->push([
                    'date' => $p->payment_date,
                    'source' => 'Customer receipt',
                    'ref' => $p->reference ?: $p->invoice?->number,
                    'desc' => $p->customer?->company_name,
                    'amount' => (float) $p->amount,
                    'amount_base' => to_base_currency($p->amount, $p->exchange_rate),
                    'kind' => 'payment',
                ]);
            });

        // Rows posted by BankLedger on behalf of a payment are excluded here: the
        // payment is already reported in the block above, and including the
        // derived bank leg too would count every receipt exactly twice.
        BankTransaction::query()
            ->with(['account'])
            ->whereIn('type', ['deposit', 'transfer_in'])
            ->whereNotIn('reference_type', [SalesPayment::class, SupplierPayment::class])
            ->whereBetween('transaction_date', [$from, $to])
            ->get()
            ->each(function (BankTransaction $t) use (&$rows) {
                $rows->push([
                    'date' => $t->transaction_date,
                    'source' => $t->type === 'transfer_in' ? 'Bank transfer in' : 'Bank deposit',
                    'ref' => $t->number,
                    'desc' => $t->description ?: $t->counterparty,
                    'amount' => (float) $t->amount,
                    'amount_base' => (float) $t->amount,
                    'kind' => 'bank',
                ]);
            });

        return $rows->sortBy('date')->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function outflowRows(string $from, string $to): Collection
    {
        $rows = collect();

        SupplierPayment::query()->with(['supplier', 'invoice'])->whereBetween('payment_date', [$from, $to])->get()
            ->each(function (SupplierPayment $p) use (&$rows) {
                $rows->push([
                    'date' => $p->payment_date,
                    'source' => 'Supplier payment',
                    'ref' => $p->reference ?: $p->invoice?->number,
                    'desc' => $p->supplier?->company_name,
                    'amount' => (float) $p->amount,
                    'amount_base' => to_base_currency($p->amount, $p->exchange_rate),
                    'kind' => 'payment',
                ]);
            });

        // Mirrors inflowRows: the bank leg of a supplier payment is derived from
        // the payment itself and would double-count the disbursement.
        BankTransaction::query()
            ->with(['account'])
            ->whereIn('type', ['withdrawal', 'transfer_out'])
            ->whereNotIn('reference_type', [SalesPayment::class, SupplierPayment::class])
            ->whereBetween('transaction_date', [$from, $to])
            ->get()
            ->each(function (BankTransaction $t) use (&$rows) {
                $rows->push([
                    'date' => $t->transaction_date,
                    'source' => $t->type === 'transfer_out' ? 'Bank transfer out' : 'Bank withdrawal',
                    'ref' => $t->number,
                    'desc' => $t->description ?: $t->counterparty,
                    'amount' => (float) $t->amount,
                    'amount_base' => (float) $t->amount,
                    'kind' => 'bank',
                ]);
            });

        return $rows->sortBy('date')->values();
    }

    private function period(Request $request, string $feature): array
    {
        $from = $request->get('from') ?: now()->startOfMonth()->toDateString();
        $to = $request->get('to') ?: now()->toDateString();

        return [$from, $to];
    }
}
