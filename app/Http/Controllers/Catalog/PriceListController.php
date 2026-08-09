<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Models\PriceList;
use App\Models\Product;
use App\Support\ExportsCsv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PriceListController extends Controller
{
    use ExportsCsv;

    public function index(Request $request): View
    {
        $priceLists = PriceList::query()
            ->withCount('items')
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', "%{$request->search}%"))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->when($request->filled('status'), fn ($q) => $q->when($request->status === 'active', fn ($q) => $q->where('is_active', true))
                ->when($request->status === 'inactive', fn ($q) => $q->where('is_active', false)))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('catalog.price_lists.index', compact('priceLists'));
    }

    public function create(): View
    {
        $products = Product::query()->orderBy('name')->get();

        return view('catalog.price_lists.create', compact('products'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

        $priceList = DB::transaction(function () use ($data, $request) {
            if ($request->boolean('is_default')) {
                PriceList::query()->update(['is_default' => false]);
            }

            $priceList = PriceList::create([
                'name' => $data['name'],
                'slug' => unique_slug(PriceList::class, $data['name']),
                'type' => $data['type'],
                'currency' => $data['currency'] ?? null,
                'markup_percent' => $data['markup_percent'] ?? 0,
                'is_default' => $request->boolean('is_default'),
                'is_active' => $request->boolean('is_active', true),
                'description' => $data['description'] ?? null,
            ]);

            $this->syncItems($priceList, $data['items'] ?? []);

            return $priceList;
        });

        return redirect()->route('catalog.price_lists.edit', $priceList)
            ->with('toasts', [['type' => 'success', 'message' => "Price list \"{$priceList->name}\" created."]]);
    }

    public function edit(PriceList $priceList): View
    {
        $priceList->load(['items.product']);
        $products = Product::query()->orderBy('name')->get();

        return view('catalog.price_lists.edit', compact('priceList', 'products'));
    }

    public function update(Request $request, PriceList $priceList): RedirectResponse
    {
        $data = $this->validateData($request, $priceList->id);

        DB::transaction(function () use ($data, $request, $priceList) {
            if ($request->boolean('is_default')) {
                PriceList::query()->whereKeyNot($priceList->id)->update(['is_default' => false]);
            }

            $priceList->update([
                'name' => $data['name'],
                'type' => $data['type'],
                'currency' => $data['currency'] ?? null,
                'markup_percent' => $data['markup_percent'] ?? 0,
                'is_default' => $request->boolean('is_default'),
                'is_active' => $request->boolean('is_active', true),
                'description' => $data['description'] ?? null,
            ]);

            $this->syncItems($priceList, $data['items'] ?? []);
        });

        return back()->with('toasts', [['type' => 'success', 'message' => "Price list \"{$priceList->name}\" updated."]]);
    }

    public function destroy(PriceList $priceList): RedirectResponse
    {
        $name = $priceList->name;
        $priceList->delete();

        return back()->with('toasts', [['type' => 'success', 'message' => "Price list \"{$name}\" deleted."]]);
    }

    public function export(Request $request): StreamedResponse
    {
        $priceLists = PriceList::query()
            ->with('items.product')
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', "%{$request->search}%"))
            ->orderBy('name')
            ->get();

        return $this->streamCsv('price-lists-'.now()->format('Y-m-d').'.csv', ['ID', 'Name', 'Type', 'Currency', 'Markup %', 'Default', 'Status', 'Items'], $priceLists->flatMap(fn (PriceList $pl) => [
            [$pl->id, $pl->name, $pl->type, $pl->currency, $pl->markup_percent, $pl->is_default ? 'Yes' : 'No', $pl->is_active ? 'Active' : 'Inactive', $pl->items_count ?? $pl->items->count()],
        ]));
    }

    private function syncItems(PriceList $priceList, array $items): void
    {
        $normalized = collect($items)
            ->filter(fn ($item) => ! empty($item['product_id']))
            ->mapWithKeys(fn ($item) => [(int) $item['product_id'] => ['price' => $item['price'] ?? 0]]);

        $priceList->products()->sync($normalized->all());
    }

    private function validateData(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('price_lists', 'name')->ignore($ignoreId)],
            'type' => ['required', Rule::in(['retail', 'wholesale', 'custom'])],
            'currency' => ['nullable', 'string', 'max:10'],
            'markup_percent' => ['nullable', 'numeric', 'min:0'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
            'description' => ['nullable', 'string', 'max:2000'],
            'items' => ['nullable', 'array'],
            'items.*.product_id' => ['required_with:items', 'integer', Rule::exists('products', 'id')],
            'items.*.price' => ['required_with:items', 'numeric', 'min:0'],
        ]);
    }
}
