<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\SalesPayment;
use App\Models\SupplierPayment;
use App\Support\ExportsCsv;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Read-only cash flow reporting across banking, sales receipts and supplier
 * payments. Money is rendered from decimal strings — never floats.
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
        $monthStart = $now->copy()->startOfMonth()->toDateString();
        $monthEnd = $now->copy()->endOfMonth()->toDateString();
        $inflowsMonth = (float) $this->inflowRows($monthStart, $monthEnd)->sum('amount');
        $outflowsMonth = (float) $this->outflowRows($monthStart, $monthEnd)->sum('amount');

        $activity = collect()
            ->concat($this->inflowRows(now()->subDays(60)->toDateString(), now()->toDateString()))
            ->concat($this->outflowRows(now()->subDays(60)->toDateString(), now()->toDateString()))
            ->sortByDesc('date')
            ->take(12)
            ->values();

        return view('cash_flow.overview', compact('cashBalance', 'receivables', 'payables', 'inflowsMonth', 'outflowsMonth', 'activity'));
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
                $buckets[$key] += (float) $item['balance'];
            }

            return collect($buckets)->map(fn ($v) => round($v, 2));
        };

        $receivablesDue = SalesInvoice::query()
            ->whereIn('status', ['sent', 'partially_paid', 'overdue'])
            ->with('customer')
            ->get()
            ->map(fn (SalesInvoice $i) => ['due_date' => $i->due_date, 'balance' => $i->balance(), 'customer' => $i->customer?->name, 'number' => $i->number]);

        $payablesDue = PurchaseInvoice::query()
            ->whereIn('status', ['sent', 'partially_paid', 'overdue'])
            ->with('supplier')
            ->get()
            ->map(fn (PurchaseInvoice $i) => ['due_date' => $i->due_date, 'balance' => $i->balance(), 'supplier' => $i->supplier?->company_name, 'number' => $i->number]);

        $inflowBuckets = $bucket($receivablesDue);
        $outflowBuckets = $bucket($payablesDue);
        $netBuckets = collect($inflowBuckets->keys())->mapWithKeys(fn ($k) => [$k => round($inflowBuckets[$k] - $outflowBuckets[$k], 2)]);

        return view('cash_flow.forecast', compact('cashBalance', 'inflowBuckets', 'outflowBuckets', 'netBuckets', 'receivablesDue', 'payablesDue'));
    }

    public function reports(Request $request): View
    {
        [$from, $to] = $this->period($request, 'reports');

        $opening = round($this->bankBalanceBefore($from), 2);
        $inflows = round((float) $this->inflowRows($from, $to)->sum('amount'), 2);
        $outflows = round((float) $this->outflowRows($from, $to)->sum('amount'), 2);
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
            $rows->push(['type' => 'Inflow', 'number' => $invoice->number, 'party' => $invoice->customer?->name, 'due_date' => $invoice->due_date?->format('Y-m-d'), 'bucket' => $step(0, max(0, (($days + 29) / 30) * 30)), 'amount' => $invoice->balance()]);
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

        return $this->streamCsv('cash-flow-statement-'.$from.'-to-'.$to.'.csv', ['Line', 'Amount'], [
            ['Opening cash & bank', $this->bankBalanceBefore($from)],
            ['Total inflows', (float) $this->inflowRows($from, $to)->sum('amount')],
            ['Total outflows', (float) $this->outflowRows($from, $to)->sum('amount')],
            ['Net change', round((float) $this->inflowRows($from, $to)->sum('amount') - (float) $this->outflowRows($from, $to)->sum('amount'), 2)],
            ['Closing cash & bank', round($this->bankBalanceBefore($from) + (float) $this->inflowRows($from, $to)->sum('amount') - (float) $this->outflowRows($from, $to)->sum('amount'), 2)],
        ]);
    }

    private function cashAndBank(): float
    {
        return round(BankAccount::query()->get()->sum(fn (BankAccount $a) => $a->balance()), 2);
    }

    private function receivables(): float
    {
        return round((float) SalesInvoice::query()->whereIn('status', ['sent', 'partially_paid', 'overdue'])->get()->sum(fn (SalesInvoice $i) => $i->balance()), 2);
    }

    private function payables(): float
    {
        return round((float) PurchaseInvoice::query()->whereIn('status', ['sent', 'partially_paid', 'overdue'])->get()->sum(fn (PurchaseInvoice $i) => $i->balance()), 2);
    }

    private function bankBalanceBefore(string $date): float
    {
        return round(BankAccount::query()->get()->sum(fn (BankAccount $a) => $a->balanceBefore($date)), 2);
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
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
                    'desc' => $p->customer?->name,
                    'amount' => (float) $p->amount,
                    'kind' => 'payment',
                ]);
            });

        BankTransaction::query()->with(['account'])->whereIn('type', ['deposit', 'transfer_in'])->whereBetween('transaction_date', [$from, $to])->get()
            ->each(function (BankTransaction $t) use (&$rows) {
                $rows->push([
                    'date' => $t->transaction_date,
                    'source' => $t->type === 'transfer_in' ? 'Bank transfer in' : 'Bank deposit',
                    'ref' => $t->number,
                    'desc' => $t->description ?: $t->counterparty,
                    'amount' => (float) $t->amount,
                    'kind' => 'bank',
                ]);
            });

        return $rows->sortBy('date')->values();
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
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
                    'kind' => 'payment',
                ]);
            });

        BankTransaction::query()->with(['account'])->whereIn('type', ['withdrawal', 'transfer_out'])->whereBetween('transaction_date', [$from, $to])->get()
            ->each(function (BankTransaction $t) use (&$rows) {
                $rows->push([
                    'date' => $t->transaction_date,
                    'source' => $t->type === 'transfer_out' ? 'Bank transfer out' : 'Bank withdrawal',
                    'ref' => $t->number,
                    'desc' => $t->description ?: $t->counterparty,
                    'amount' => (float) $t->amount,
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
