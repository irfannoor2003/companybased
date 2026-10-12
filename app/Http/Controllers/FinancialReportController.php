<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\JournalEntryItem;
use App\Services\Accounting\LedgerPosting;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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

        // Positions as at $to come from one grouped query rather than a hydration
        // of every posted line since the ledger began. Only the ledger listing
        // below needs individual rows.
        $positionBalances = $this->accountBalancesUpTo($to);

        $summary = $this->profitAndLoss($from, $to);
        $balanceSheet = $this->balanceSheet($positionBalances);
        $trial = $this->trialBalance($positionBalances);
        $ledger = $periodItems;

        // Chart data for P&L trend (monthly totals for the period)
        $plChartData = $this->profitAndLossChartData($from, $to);

        // Chart data for asset/liquidity position
        $bsChartData = $this->balanceSheetChartData($positionBalances);

        $plChartJson = json_encode([
            'labels' => $plChartData['labels'],
            'datasets' => [
                // Roles, not hex: the browser resolves these from the
                // --color-series-* tokens so the chart follows the theme.
                ['label' => 'Revenue', 'data' => $plChartData['revenue'], 'cbRole' => 'primary', 'fill' => true],
                ['label' => 'Expenses', 'data' => $plChartData['expenses'], 'cbRole' => 'danger', 'fill' => true],
            ],
        ]);

        $plOptionsJson = json_encode([
            'responsive' => true,
            'plugins' => [
                'legend' => ['position' => 'bottom'],
                'title' => ['display' => true, 'text' => 'P&L by Month'],
            ],
            'scales' => ['y' => ['beginAtZero' => true, 'title' => ['display' => true, 'text' => 'Amount']]],
        ]);

        $bsChartJson = json_encode([
            'labels' => $bsChartData['labels'],
            'datasets' => [[
                'data' => $bsChartData['data'],
                'backgroundColor' => $bsChartData['colors'],
            ]],
        ]);

        $bsOptionsJson = json_encode([
            'responsive' => true,
            'plugins' => [
                'legend' => ['position' => 'bottom'],
                'title' => ['display' => true, 'text' => 'Financial Position'],
            ],
        ]);

        return view('reports.financial', compact('from', 'to', 'summary', 'balanceSheet', 'trial', 'ledger', 'plChartData', 'bsChartData', 'plChartJson', 'plOptionsJson', 'bsChartJson', 'bsOptionsJson'));
    }

    /**
     * Monthly P&L data for chart.
     *
     * Grouped by month in SQL. This previously loaded every posted line in the
     * period and then re-filtered that collection once per month in PHP, which is
     * an extra full pass over the period's ledger for every month on the chart.
     */
    private function profitAndLossChartData(string $from, string $to): array
    {
        $rows = JournalEntryItem::query()
            ->join('accounts', 'accounts.id', '=', 'journal_entry_items.account_id')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_items.journal_entry_id')
            ->where('journal_entries.status', 'posted')
            ->whereBetween('journal_entries.entry_date', [$from, $to])
            ->whereIn('accounts.type', ['revenue', 'expense'])
            ->groupBy('accounts.type', DB::raw("DATE_FORMAT(journal_entries.entry_date, '%Y-%m')"))
            ->selectRaw('accounts.type')
            ->selectRaw("DATE_FORMAT(journal_entries.entry_date, '%Y-%m') AS period")
            ->selectRaw('COALESCE(SUM(journal_entry_items.credit - journal_entry_items.debit), 0) AS credit_net')
            ->selectRaw('COALESCE(SUM(journal_entry_items.debit - journal_entry_items.credit), 0) AS debit_net')
            ->get()
            ->keyBy(fn ($row) => $row->type.':'.$row->period);

        $months = [];
        $revenue = [];
        $expenses = [];

        for ($m = strtotime($from); $m <= strtotime($to); $m = strtotime('+1 month', $m)) {
            // Matched as a 'Y-m' key. Comparing the Carbon entry_date against a date
            // string stringifies it to 'Y-m-d H:i:s', so the final day of the month
            // compared greater than 'Y-m-d' and was silently dropped from every month
            // of the trend.
            $period = date('Y-m', $m);

            $months[] = date('M Y', $m);
            $revenue[] = round((float) ($rows->get('revenue:'.$period)->credit_net ?? 0), 2);
            $expenses[] = round((float) ($rows->get('expense:'.$period)->debit_net ?? 0), 2);
        }

        return [
            'labels' => $months,
            'revenue' => $revenue,
            'expenses' => $expenses,
        ];
    }

    /**
     * Asset/liability position over time data for chart.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $balances
     */
    private function balanceSheetChartData(Collection $balances): array
    {
        $assets = round($this->netBalanceFromRows($balances, ['asset']), 2);
        $liabilities = round(-1 * $this->netBalanceFromRows($balances, ['liability']), 2);
        $equity = round(-1 * $this->netBalanceFromRows($balances, ['equity']), 2);

        return [
            'labels' => ['Assets', 'Liabilities', 'Equity'],
            'data' => [$assets, $liabilities, $equity],
            'colors' => ['#10b981', '#f56565', '#64748b'],
        ];
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
     * Revenue and expense totals for the period, aggregated in SQL.
     *
     * This used to filter and sum the hydrated line collection that `itemsBetween()`
     * returns for the ledger listing. The two aggregates are the only thing the
     * summary needs, and computing them in the database means the period's journal
     * is walked once instead of being fully materialised and then walked twice more
     * in PHP. The arithmetic is unchanged: revenue is credit less debit and expense
     * is debit less credit, and an account carries exactly one type, so grouping by
     * type is equivalent to filtering lines by type.
     *
     * @return array{revenue: float, expenses: float, net: float}
     */
    private function profitAndLoss(string $from, string $to): array
    {
        $totals = JournalEntryItem::query()
            ->join('accounts', 'accounts.id', '=', 'journal_entry_items.account_id')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_items.journal_entry_id')
            ->where('journal_entries.status', 'posted')
            ->whereBetween('journal_entries.entry_date', [$from, $to])
            ->whereIn('accounts.type', ['revenue', 'expense'])
            ->groupBy('accounts.type')
            ->selectRaw('accounts.type')
            ->selectRaw('COALESCE(SUM(journal_entry_items.credit - journal_entry_items.debit), 0) AS credit_net')
            ->selectRaw('COALESCE(SUM(journal_entry_items.debit - journal_entry_items.credit), 0) AS debit_net')
            ->get()
            ->keyBy('type');

        $revenue = (float) ($totals['revenue']->credit_net ?? 0);
        $expenses = (float) ($totals['expense']->debit_net ?? 0);

        return [
            'revenue' => round($revenue, 2),
            'expenses' => round($expenses, 2),
            'net' => round($revenue - $expenses, 2),
        ];
    }

    /**
     * Per-account net balances for every posted entry on or before $to.
     *
     * The balance sheet, the trial balance and the position chart only ever need
     * sums, but they were being fed a hydration of every posted journal line since
     * the ledger began. On a real deployment that is the whole general ledger in
     * memory on every request to this screen.
     *
     * One grouped query returns one row per account, from which the per-type totals
     * are summed. The arithmetic is unchanged: balance is debit less credit, and
     * each account carries exactly one type, so grouping by account and reading the
     * type off the join is equivalent to filtering lines by type.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function accountBalancesUpTo(string $to): Collection
    {
        return JournalEntryItem::query()
            ->join('accounts', 'accounts.id', '=', 'journal_entry_items.account_id')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_items.journal_entry_id')
            ->where('journal_entries.status', 'posted')
            ->where('journal_entries.entry_date', '<=', $to)
            ->groupBy(
                'journal_entry_items.account_id',
                'accounts.type',
                'accounts.code',
                'accounts.name',
            )
            ->selectRaw('journal_entry_items.account_id')
            ->selectRaw('accounts.type')
            ->selectRaw('accounts.code')
            ->selectRaw('accounts.name')
            ->selectRaw('COALESCE(SUM(journal_entry_items.debit - journal_entry_items.credit), 0) AS balance')
            ->get();
    }

    /**
     * Net debit-less-credit balance across the given account types, from the
     * grouped balances.
     */
    private function netBalanceFromRows(Collection $balances, array $types): float
    {
        return (float) $balances
            ->filter(fn ($row) => in_array($row->type, $types, true))
            ->sum(fn ($row) => (float) $row->balance);
    }

    /**
     * Assets, liabilities and equity, with retained earnings as a REAL figure.
     *
     * The retained earnings line used to be `assets - liabilities - equity`, which
     * forces the statement to balance by arithmetic whatever the ledger says: a
     * transposition, a misposting or a missing entry simply moved the retained line
     * to hide it, so the balance sheet always agreed and never told you anything.
     *
     * It now reads account 3100, which a year-end close fills with the profit the
     * business actually made. A ledger that does not add up therefore shows up as
     * an unbalanced balance sheet rather than being quietly absorbed -- and when
     * the books ARE right, the two figures agree and that agreement means
     * something.
     *
     * @return array{assets: float, liabilities: float, equity: float, retained: float}
     */
    private function balanceSheet(Collection $balances): array
    {
        $assets = round($this->netBalanceFromRows($balances, ['asset']), 2);
        $liabilities = round(-1 * $this->netBalanceFromRows($balances, ['liability']), 2);
        $equity = round(-1 * $this->netBalanceFromRows($balances, ['equity']), 2);

        // Rebuilt from the grouped balances rather than trusting the passed-in
        // total, so the line reflects exactly the posted entries as at the date.
        $retained = round(
            (float) ($balances->firstWhere('code', LedgerPosting::RETAINED_EARNINGS)->balance ?? 0),
            2,
        );

        return [
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'retained' => $retained,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $balances
     * @return array{rows: \Illuminate\Support\Collection<int, array<string, mixed>>, total_debit: float, total_credit: float}
     */
    private function trialBalance(Collection $balances): array
    {
        $rows = $balances
            ->map(function ($row) {
                $balance = round((float) $row->balance, 2);

                return [
                    'code' => $row->code,
                    'name' => $row->name,
                    'type_label' => Account::typeLabel($row->type),
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
}