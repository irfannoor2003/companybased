<?php

namespace App\Http\Controllers\FixedAsset;

use App\Http\Controllers\Controller;
use App\Models\FixedAsset;
use App\Models\FixedAssetDepreciation;
use App\Support\ExportsCsv;
use App\Support\ExportsJson;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DepreciationController extends Controller
{
    use ExportsCsv;
    use ExportsJson;

    public function index(Request $request): View
    {
        $period = $request->filled('period') ? $request->period : now()->format('Y-m');

        $records = FixedAssetDepreciation::query()
            ->where('period', $period)
            ->with('asset')
            ->orderBy('id')
            ->get();

        $periods = FixedAssetDepreciation::query()->distinct()->orderByDesc('period')->pluck('period');

        $periodTotal = round((float) $records->sum('amount'), 2);

        $assets = FixedAsset::query()
            ->withCount('depreciations')
            ->orderBy('name')
            ->get();

        return view('fixed_assets.depreciation.index', compact('records', 'period', 'periods', 'periodTotal', 'assets'));
    }

    public function run(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'period' => ['required', 'date_format:Y-m'],
            'assets' => ['required', 'array'],
            'assets.*' => ['integer'],
        ]);

        $period = $data['period'];
        $created = 0;
        $skipped = 0;

        $assets = FixedAsset::query()->whereIn('id', $data['assets'])->get();

        foreach ($assets as $asset) {
            if ($asset->status === 'disposed' || $asset->isFullyDepreciated()) {
                $skipped++;

                continue;
            }

            $exists = FixedAssetDepreciation::query()
                ->where('fixed_asset_id', $asset->id)
                ->where('period', $period)
                ->exists();

            if ($exists) {
                $skipped++;

                continue;
            }

            $amount = $asset->monthlyDepreciation();

            if ($amount <= 0) {
                $skipped++;

                continue;
            }

            FixedAssetDepreciation::create([
                'fixed_asset_id' => $asset->id,
                'period' => $period,
                'amount' => (string) $amount,
            ]);

            $created++;
        }

        $message = "Depreciation for {$period}: {$created} record(s) created";

        if ($skipped > 0) {
            $message .= ", {$skipped} skipped";
        }

        return redirect()->route('fixed_assets.depreciation.index', ['period' => $period])
            ->with('toasts', [['type' => $created > 0 ? 'success' : 'info', 'message' => $message.'.']]);
    }

    public function export(Request $request): StreamedResponse
    {
        $period = $request->filled('period') ? $request->period : now()->format('Y-m');

        $records = FixedAssetDepreciation::query()
            ->where('period', $period)
            ->with('asset')
            ->orderBy('id')
            ->get();

        $rows = $records->map(fn (FixedAssetDepreciation $d) => [
            'period' => $d->period,
            'asset_code' => $d->asset?->asset_code,
            'asset_name' => $d->asset?->name,
            'amount' => $d->amount,
        ]);

        $extension = $request->query('format') === 'json' ? 'json' : 'csv';
        $filename = "depreciation-{$period}.{$extension}";

        return $request->query('format') === 'json'
            ? $this->streamJson($filename, $rows)
            : $this->streamCsv($filename, ['Period', 'Asset code', 'Asset', 'Amount'], $rows->values());
    }
}