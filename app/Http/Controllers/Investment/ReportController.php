<?php

namespace App\Http\Controllers\Investment;

use App\Http\Controllers\Controller;
use App\Models\Investment;
use App\Models\InvestmentDividend;
use App\Support\ExportsCsv;
use App\Support\ExportsJson;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    use ExportsCsv;
    use ExportsJson;

    public function index(Request $request): View
    {
        $investments = Investment::query()->orderBy('name')->get();

        $byType = $investments->groupBy(fn ($i) => ucfirst(str_replace('_', ' ', $i->type)))
            ->map(function ($group) {
                $cost = round((float) $group->sum('total_cost'), 2);
                $value = round((float) $group->sum(fn ($i) => $i->marketValue()), 2);

                return [
                    'type' => $group->first()->type,
                    'count' => $group->count(),
                    'cost' => $cost,
                    'value' => $value,
                    'gain_loss' => round($value - $cost, 2),
                ];
            })
            ->values()
            ->sortByDesc('value')
            ->values();

        $totals = [
            'count' => $investments->count(),
            'cost' => round((float) $investments->sum('total_cost'), 2),
            'value' => round((float) $investments->sum(fn ($i) => $i->marketValue()), 2),
            'gain_loss' => round((float) $investments->sum(fn ($i) => $i->gainLoss()), 2),
            'dividends' => round((float) InvestmentDividend::query()->sum('amount'), 2),
        ];
        $totals['return_pct'] = $totals['cost'] > 0 ? round(($totals['gain_loss'] / $totals['cost']) * 100, 2) : 0.0;

        $byYear = InvestmentDividend::query()
            ->selectRaw('year(dividend_date) as year, sum(amount) as total')
            ->groupBy('year')
            ->orderByDesc('year')
            ->get();

        return view('investments.reports.index', compact('byType', 'totals', 'byYear'));
    }

    public function export(Request $request): StreamedResponse
    {
        $investments = Investment::query()->orderBy('name')->get();

        $rows = collect([
            ['Portfolio summary'],
            ['Total invested', number_format((float) $investments->sum('total_cost'), 2)],
            ['Market value', number_format((float) $investments->sum(fn ($i) => $i->marketValue()), 2)],
            ['Gain / Loss', number_format((float) $investments->sum(fn ($i) => $i->gainLoss()), 2)],
            ['Dividends received', number_format((float) InvestmentDividend::query()->sum('amount'), 2)],
            [''],
            ['Allocation by type'],
            ['Type', 'Count', 'Cost', 'Market value', 'Gain/Loss'],
        ]);

        $byType = $investments->groupBy(fn ($i) => ucfirst(str_replace('_', ' ', $i->type)));
        foreach ($byType as $type => $group) {
            $cost = round((float) $group->sum('total_cost'), 2);
            $value = round((float) $group->sum(fn ($i) => $i->marketValue()), 2);
            $rows->push([$type, $group->count(), number_format($cost, 2), number_format($value, 2), number_format($value - $cost, 2)]);
        }

        $filename = 'investment-report-'.now()->format('Y-m-d').'.'.($request->query('format') === 'json' ? 'json' : 'csv');

        return $request->query('format') === 'json'
            ? $this->streamJson($filename, [
                'totals' => [
                    'cost' => round((float) $investments->sum('total_cost'), 2),
                    'value' => round((float) $investments->sum(fn ($i) => $i->marketValue()), 2),
                    'dividends' => round((float) InvestmentDividend::query()->sum('amount'), 2),
                ],
            ])
            : $this->streamCsv($filename, ['Field', 'Type', 'Count', 'Cost', 'Value', 'Gain/Loss', 'A', 'B'], $rows);
    }
}