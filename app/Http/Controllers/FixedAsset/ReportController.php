<?php

namespace App\Http\Controllers\FixedAsset;

use App\Http\Controllers\Controller;
use App\Models\FixedAsset;
use App\Models\FixedAssetDepreciation;
use App\Models\FixedAssetDisposal;
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
        $assets = FixedAsset::query()->orderBy('name')->get();

        $byCategory = $assets->groupBy(fn ($a) => $a->category ?: 'Uncategorised')
            ->map(function ($group) {
                $cost = round((float) $group->sum('purchase_cost'), 2);
                $depreciation = round((float) $group->sum(fn ($a) => $a->accumulatedDepreciation()), 2);

                return [
                    'category' => $group->first()->category ?: 'Uncategorised',
                    'count' => $group->count(),
                    'cost' => $cost,
                    'depreciation' => $depreciation,
                    'book_value' => round($cost - $depreciation, 2),
                ];
            })
            ->values()
            ->sortByDesc('book_value')
            ->values();

        $totals = [
            'count' => $assets->count(),
            'cost' => round((float) $assets->sum('purchase_cost'), 2),
            'depreciation' => round((float) $assets->sum(fn ($a) => $a->accumulatedDepreciation()), 2),
            'book_value' => round((float) $assets->sum(fn ($a) => $a->bookValue()), 2),
            'disposals' => FixedAssetDisposal::query()->count(),
            'disposal_proceeds' => round((float) FixedAssetDisposal::query()->sum('proceeds'), 2),
        ];

        $byPeriod = FixedAssetDepreciation::query()
            ->selectRaw('period, sum(amount) as total')
            ->groupBy('period')
            ->orderByDesc('period')
            ->get();

        $depreciationChartData = $this->depreciationChartData();

        $chartDataJson = json_encode([
            'labels' => $depreciationChartData['labels'],
            'datasets' => [[
                'label' => 'Depreciation',
                'data' => $depreciationChartData['data'],
                'backgroundColor' => 'rgba(168, 85, 247, 0.6)',
                'borderColor' => '#a855f7',
            ]],
        ]);

        $chartOptionsJson = json_encode([
            'responsive' => true,
            'plugins' => [
                'legend' => ['position' => 'bottom'],
                'title' => ['display' => true, 'text' => 'Depreciation by Period'],
            ],
            'scales' => [
                'y' => ['title' => ['display' => true, 'text' => 'Amount'], 'beginAtZero' => true],
            ],
        ]);

        return view('fixed_assets.reports.index', compact('assets', 'byCategory', 'totals', 'byPeriod', 'depreciationChartData', 'chartDataJson', 'chartOptionsJson'));
    }

    /**
     * Depreciation trend chart data for the last 12 periods.
     */
    private function depreciationChartData(): array
    {
        $periods = FixedAssetDepreciation::query()
            ->selectRaw('period, sum(amount) as total')
            ->groupBy('period')
            ->orderByDesc('period')
            ->take(12)
            ->get();

        $labels = [];
        $data = [];

        foreach ($periods as $row) {
            $labels[] = $row->period;
            $data[] = (float) $row->total;
        }

        // Pad with zeros to have up to 12 months
        while (count($labels) < 12) {
            array_unshift($labels, null);
            array_unshift($data, 0);
        }

        return [
            'labels' => array_slice($labels, 0, 12),
            'data' => array_slice($data, 0, 12),
        ];
    }

    public function export(Request $request): StreamedResponse
    {
        $assets = FixedAsset::query()->orderBy('name')->get();

        $rows = collect([
            ['Category report'],
            ['Category', 'Count', 'Cost', 'Depreciation', 'Book value'],
        ]);

        $byCategory = $assets->groupBy(fn ($a) => $a->category ?: 'Uncategorised');
        foreach ($byCategory as $category => $items) {
            $cost = round((float) $items->sum('purchase_cost'), 2);
            $depreciation = round((float) $items->sum(fn ($a) => $a->accumulatedDepreciation()), 2);
            $rows->push([$category, $items->count(), number_format($cost, 2), number_format($depreciation, 2), number_format($cost - $depreciation, 2)]);
        }

        $rows->push(['']);
        $rows->push(['Asset register']);
        $rows->push(['Code', 'Name', 'Category', 'Cost', 'Depreciation', 'Book value', 'Status']);
        foreach ($assets as $a) {
            $rows->push([
                $a->asset_code, $a->name, $a->category ?: '',
                number_format((float) $a->purchase_cost, 2),
                number_format($a->accumulatedDepreciation(), 2),
                number_format($a->bookValue(), 2),
                ucfirst(str_replace('_', ' ', $a->status)),
            ]);
        }

        $filename = 'fixed-assets-report-'.now()->format('Y-m-d').'.'.($request->query('format') === 'json' ? 'json' : 'csv');

        return $request->query('format') === 'json'
            ? $this->streamJson($filename, ['totals' => [
                'assets' => $assets->count(),
                'cost' => round((float) $assets->sum('purchase_cost'), 2),
                'book_value' => round((float) $assets->sum(fn ($a) => $a->bookValue()), 2),
            ]])
            : $this->streamCsv($filename, ['Section', 'Category', 'Count', 'Total', 'Depreciation', 'Book value', 'Code', 'Name', 'Status'], $rows);
    }
}