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

        return view('fixed_assets.reports.index', compact('assets', 'byCategory', 'totals', 'byPeriod'));
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