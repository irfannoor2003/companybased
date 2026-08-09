<?php

namespace App\Http\Controllers\FixedAsset;

use App\Http\Controllers\Controller;
use App\Models\FixedAsset;
use App\Support\ExportsCsv;
use App\Support\ExportsJson;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AssetController extends Controller
{
    use ExportsCsv;
    use ExportsJson;

    public function index(Request $request): View
    {
        $assets = FixedAsset::query()
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->category))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->orderByDesc('purchase_date')
            ->paginate(20)
            ->withQueryString();

        $categories = FixedAsset::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category');

        $stats = [
            'count' => FixedAsset::query()->count(),
            'cost' => round((float) FixedAsset::query()->sum('purchase_cost'), 2),
            'depreciation' => round((float) FixedAsset::query()->get()->sum(fn ($a) => $a->accumulatedDepreciation()), 2),
            'book_value' => round((float) FixedAsset::query()->get()->sum(fn ($a) => $a->bookValue()), 2),
        ];

        return view('fixed_assets.assets.index', compact('assets', 'categories', 'stats'));
    }

    public function create(): View
    {
        return view('fixed_assets.assets.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

        $asset = FixedAsset::create([
            'asset_code' => next_document_number('fixed_asset', 'AST'),
            'name' => $data['name'],
            'category' => $data['category'] ?? null,
            'purchase_date' => $data['purchase_date'] ?? null,
            'purchase_cost' => (string) $data['purchase_cost'],
            'salvage_value' => (string) ($data['salvage_value'] ?? 0),
            'useful_life_months' => (int) $data['useful_life_months'],
            'depreciation_method' => $data['depreciation_method'],
            'depreciation_rate' => $data['depreciation_rate'] ?? null,
            'location' => $data['location'] ?? null,
            'department' => $data['department'] ?? null,
            'serial_number' => $data['serial_number'] ?? null,
            'supplier' => $data['supplier'] ?? null,
            'status' => $data['status'],
            'notes' => $data['notes'] ?? null,
        ]);

        return redirect()->route('fixed_assets.assets.index')
            ->with('toasts', [['type' => 'success', 'message' => "Asset {$asset->asset_code} created."]]);
    }

    public function edit(FixedAsset $asset): View
    {
        return view('fixed_assets.assets.edit', compact('asset'));
    }

    public function update(Request $request, FixedAsset $asset): RedirectResponse
    {
        $data = $this->validateData($request);

        $asset->update([
            'name' => $data['name'],
            'category' => $data['category'] ?? null,
            'purchase_date' => $data['purchase_date'] ?? null,
            'purchase_cost' => (string) $data['purchase_cost'],
            'salvage_value' => (string) ($data['salvage_value'] ?? 0),
            'useful_life_months' => (int) $data['useful_life_months'],
            'depreciation_method' => $data['depreciation_method'],
            'depreciation_rate' => $data['depreciation_rate'] ?? null,
            'location' => $data['location'] ?? null,
            'department' => $data['department'] ?? null,
            'serial_number' => $data['serial_number'] ?? null,
            'supplier' => $data['supplier'] ?? null,
            'status' => $data['status'],
            'notes' => $data['notes'] ?? null,
        ]);

        return back()->with('toasts', [['type' => 'success', 'message' => "Asset {$asset->asset_code} updated."]]);
    }

    public function destroy(FixedAsset $asset): RedirectResponse
    {
        $asset->delete();

        return redirect()->route('fixed_assets.assets.index')
            ->with('toasts', [['type' => 'success', 'message' => "Asset {$asset->asset_code} deleted."]]);
    }

    public function export(Request $request): StreamedResponse
    {
        $assets = FixedAsset::query()
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->category))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->orderByDesc('purchase_date')
            ->get();

        $rows = $assets->map(fn (FixedAsset $a) => [
            'asset_code' => $a->asset_code,
            'name' => $a->name,
            'category' => $a->category,
            'purchase_date' => $a->purchase_date?->format('Y-m-d'),
            'purchase_cost' => $a->purchase_cost,
            'salvage_value' => $a->salvage_value,
            'depreciation' => $a->accumulatedDepreciation(),
            'book_value' => $a->bookValue(),
            'status' => ucfirst(str_replace('_', ' ', $a->status)),
            'location' => $a->location,
        ]);

        $filename = 'fixed-assets-'.now()->format('Y-m-d').'.'.($request->query('format') === 'json' ? 'json' : 'csv');

        return $request->query('format') === 'json'
            ? $this->streamJson($filename, $rows)
            : $this->streamCsv($filename, ['Code', 'Name', 'Category', 'Purchase date', 'Cost', 'Salvage', 'Depreciation', 'Book value', 'Status', 'Location'], $rows->values());
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'category' => ['nullable', 'string', 'max:190'],
            'purchase_date' => ['nullable', 'date'],
            'purchase_cost' => ['required', 'numeric', 'min:0'],
            'salvage_value' => ['nullable', 'numeric', 'min:0'],
            'useful_life_months' => ['required', 'integer', 'min:0'],
            'depreciation_method' => ['required', 'in:straight_line,reducing_balance'],
            'depreciation_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'location' => ['nullable', 'string', 'max:190'],
            'department' => ['nullable', 'string', 'max:190'],
            'serial_number' => ['nullable', 'string', 'max:190'],
            'supplier' => ['nullable', 'string', 'max:190'],
            'status' => ['required', 'in:in_use,stored,disposed'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }
}