<?php

namespace App\Http\Controllers\FixedAsset;

use App\Http\Controllers\Controller;
use App\Models\FixedAsset;
use App\Models\FixedAssetDisposal;
use App\Support\ExportsCsv;
use App\Support\ExportsJson;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DisposalController extends Controller
{
    use ExportsCsv;
    use ExportsJson;

    public function index(Request $request): View
    {
        $disposals = FixedAssetDisposal::query()
            ->with('asset')
            ->when($request->filled('search'), function ($q) use ($request) {
                $q->whereHas('asset', fn ($asset) => $asset->where('name', 'like', "%{$request->search}%")->orWhere('asset_code', 'like', "%{$request->search}%"));
            })
            ->when($request->filled('method'), fn ($q) => $q->where('method', $request->method))
            ->when($request->filled('from'), fn ($q) => $q->where('disposal_date', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->where('disposal_date', '<=', $request->to))
            ->orderByDesc('disposal_date')
            ->paginate(20)
            ->withQueryString();

        $totalProceeds = round((float) FixedAssetDisposal::query()->sum('proceeds'), 2);

        return view('fixed_assets.disposals.index', compact('disposals', 'totalProceeds'));
    }

    public function create(): View
    {
        $assets = FixedAsset::query()
            ->where('status', '!=', 'disposed')
            ->orderBy('name')
            ->get();

        return view('fixed_assets.disposals.create', compact('assets'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

        $asset = FixedAsset::query()->findOrFail($data['fixed_asset_id']);

        $disposal = FixedAssetDisposal::create([
            'fixed_asset_id' => $asset->id,
            'disposal_date' => $data['disposal_date'],
            'method' => $data['method'],
            'proceeds' => (string) $data['proceeds'],
            'book_value' => (string) $asset->bookValue(),
            'notes' => $data['notes'] ?? null,
        ]);

        $asset->update(['status' => 'disposed']);

        return redirect()->route('fixed_assets.disposals.index')
            ->with('toasts', [['type' => 'success', 'message' => "Disposal for {$asset->asset_code} recorded."]]);
    }

    public function edit(FixedAssetDisposal $disposal): View
    {
        return view('fixed_assets.disposals.edit', compact('disposal'));
    }

    public function update(Request $request, FixedAssetDisposal $disposal): RedirectResponse
    {
        $data = $this->validateData($request, $disposal->fixed_asset_id);

        $disposal->update([
            'disposal_date' => $data['disposal_date'],
            'method' => $data['method'],
            'proceeds' => (string) $data['proceeds'],
            'notes' => $data['notes'] ?? null,
        ]);

        return back()->with('toasts', [['type' => 'success', 'message' => 'Disposal updated.']]);
    }

    public function destroy(FixedAssetDisposal $disposal): RedirectResponse
    {
        $assetId = $disposal->fixed_asset_id;
        $disposal->delete();

        FixedAsset::query()->where('id', $assetId)->update(['status' => 'in_use']);

        return redirect()->route('fixed_assets.disposals.index')
            ->with('toasts', [['type' => 'success', 'message' => 'Disposal deleted.']]);
    }

    public function export(Request $request): StreamedResponse
    {
        $disposals = FixedAssetDisposal::query()
            ->with('asset')
            ->when($request->filled('search'), function ($q) use ($request) {
                $q->whereHas('asset', fn ($asset) => $asset->where('name', 'like', "%{$request->search}%")->orWhere('asset_code', 'like', "%{$request->search}%"));
            })
            ->when($request->filled('method'), fn ($q) => $q->where('method', $request->method))
            ->when($request->filled('from'), fn ($q) => $q->where('disposal_date', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->where('disposal_date', '<=', $request->to))
            ->orderByDesc('disposal_date')
            ->get();

        $rows = $disposals->map(fn (FixedAssetDisposal $d) => [
            'disposal_date' => $d->disposal_date?->format('Y-m-d'),
            'asset_code' => $d->asset?->asset_code,
            'asset_name' => $d->asset?->name,
            'method' => ucfirst($d->method),
            'proceeds' => $d->proceeds,
            'book_value' => $d->book_value,
            'gain_loss' => round((float) $d->proceeds - (float) $d->book_value, 2),
        ]);

        $filename = 'fixed-asset-disposals-'.now()->format('Y-m-d').'.'.($request->query('format') === 'json' ? 'json' : 'csv');

        return $request->query('format') === 'json'
            ? $this->streamJson($filename, $rows)
            : $this->streamCsv($filename, ['Date', 'Asset code', 'Asset', 'Method', 'Proceeds', 'Book value', 'Gain/Loss'], $rows->values());
    }

    private function validateData(Request $request, ?int $ignoreAssetId = null): array
    {
        $assets = FixedAsset::query()
            ->when($ignoreAssetId, fn ($q) => $q->orWhere('id', $ignoreAssetId))
            ->where('status', '!=', 'disposed')
            ->pluck('id')
            ->push($ignoreAssetId)
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $request->validate([
            'fixed_asset_id' => ['required', 'integer', 'in:'.implode(',', $assets)],
            'disposal_date' => ['required', 'date'],
            'method' => ['required', 'in:sold,scrapped,donated,other'],
            'proceeds' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }
}