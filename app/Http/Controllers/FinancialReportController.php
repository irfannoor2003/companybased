<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\JournalEntryItem;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Financial reports over the double-entry journal (posted entries only).
 * The profit & loss and the general ledger reflect the selected period,
 * while the balance sheet and trial balance are positions as of the "to" date.
 */
class FinancialReportController extends Controller
{
    public function index(Request $request): View
    {
        $from = $request->get('from') ?: now()->startOfMonth()->toDateString();
        $to = $request->get('to') ?: now()->toDateString();

        $periodItems = $this->itemsBetween($from, $to);
        $positionItems = $this->itemsUpTo($to);

        $summary = $this->profitAndLoss($periodItems);
        $balanceSheet = $this->balanceSheet($positionItems);
        $trial = $this->trialBalance($positionItems);
        $ledger = $periodItems;

        return view('reports.financial', compact('from', 'to', 'summary', 'balanceSheet', 'trial', 'ledger'));
    }

    /**
     * @return \Illuminate\Support\Collection<int, JournalEntryItem>
     */
    private function itemsBetween(string $from, string $to): Collection
    {
        return JournalEntryItem::query()
            ->with(['entry', 'account'])
            ->whereHas('entry', fn ($q) => $q->where('status', 'posted')->whereBetween('entry_date', [$from, $to]))
            ->orderBy('id')
            ->get()
            ->sortBy(fn (JournalEntryItem $i) => [$i->entry?->entry_date?->toDateString() ?? '', $i->id])
            ->values();
    }

    /**
     * Posted journal lines dated on or before $to (used for positions).
     *
     * @return \Illuminate\Support\Collection<int, JournalEntryItem>
     */
    private function itemsUpTo(string $to): Collection
    {
        return JournalEntryItem::query()
            ->with(['account'])
            ->whereHas('entry', fn ($q) => $q->where('status', 'posted')->where('entry_date', '<=', $to))
            ->get();
    }

    /**
     * @return array{revenue: float, expenses: float, net: float}
     */
    private function profitAndLoss(Collection $items): array
    {
        $revenue = $items->filter(fn (JournalEntryItem $i) => $i->account?->type === 'revenue')
            ->sum(fn (JournalEntryItem $i) => (float) $i->credit - (float) $i->debit);
        $expenses = $items->filter(fn (JournalEntryItem $i) => $i->account?->type === 'expense')
            ->sum(fn (JournalEntryItem $i) => (float) $i->debit - (float) $i->credit);

        return [
            'revenue' => round((float) $revenue, 2),
            'expenses' => round((float) $expenses, 2),
            'net' => round((float) $revenue - (float) $expenses, 2),
        ];
    }

    /**
     * @return array{assets: float, liabilities: float, equity: float, retained: float}
     */
    private function balanceSheet(Collection $items): array
    {
        $assets = round($this->netBalance($items, ['asset']), 2);
        $liabilities = round(-1 * $this->netBalance($items, ['liability']), 2);
        $equity = round(-1 * $this->netBalance($items, ['equity']), 2);
        $retained = round($assets - $liabilities - $equity, 2);

        return [
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'retained' => $retained,
        ];
    }

    /**
     * @return array{rows: Collection<int, array<string, mixed>>, total_debit: float, total_credit: float}
     */
    private function trialBalance(Collection $items): array
    {
        $rows = $items
            ->groupBy('account_id')
            ->map(function (Collection $group) {
                $account = $group->first()?->account;
                $balance = round($group->sum(fn (JournalEntryItem $i) => (float) $i->debit - (float) $i->credit), 2);

                return [
                    'code' => $account?->code,
                    'name' => $account?->name,
                    'type_label' => $account ? Account::typeLabel($account->type) : '—',
                    'balance' => $balance,
                ];
            })
            ->filter(fn ($row) => abs($row['balance']) > 0.004)
            ->sortBy('code')
            ->map(function (array $row): array {
                $balance = $row['balance'];

                return [
                    'code' => $row['code'],
                    'name' => $row['name'],
                    'type_label' => $row['type_label'],
                    'debit' => $balance > 0 ? $balance : null,
                    'credit' => $balance < 0 ? round(-1 * $balance, 2) : null,
                ];
            })
            ->values();

        $totalDebit = round((float) $rows->sum('debit'), 2);
        $totalCredit = round((float) $rows->sum('credit'), 2);

        return [
            'rows' => $rows,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
        ];
    }

    /**
     * Net debit-less-credit balance across the given account types.
     */
    private function netBalance(Collection $items, array $types): float
    {
        return (float) $items->filter(fn (JournalEntryItem $i) => $i->account && in_array($i->account->type, $types, true))
            ->sum(fn (JournalEntryItem $i) => (float) $i->debit - (float) $i->credit);
    }
}