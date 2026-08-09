<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\InventoryWarehouse;
use App\Models\Product;
use App\Support\ExportsCsv;
use App\Support\InventoryLedger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ItemController extends Controller
{
    use ExportsCsv;

    public function index(Request $request): View
    {
        $onHandSql = '(SELECT COALESCE(SUM(quantity), 0) FROM inventory_stock WHERE inventory_stock.item_id = inventory_items.id)';

        $items = InventoryItem::query()
            ->with(['product', 'stock.warehouse'])
            ->withSum('stock as on_hand', 'quantity')
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->when($request->filled('warehouse'), fn ($q) => $q->whereHas('stock', fn ($s) => $s->where('warehouse_id', $request->warehouse)))
            ->when($request->filled('status'), fn ($q) => $q
                ->when($request->status === 'active', fn ($q) => $q->where('is_active', true))
                ->when($request->status === 'inactive', fn ($q) => $q->where('is_active', false)))
            ->when($request->filled('stock_status'), function ($q) use ($request, $onHandSql) {
                $q->when($request->stock_status === 'low', fn ($q) => $q->whereRaw("{$onHandSql} <= inventory_items.reorder_level AND inventory_items.reorder_level > 0"))
                    ->when($request->stock_status === 'out', fn ($q) => $q->whereRaw("{$onHandSql} <= 0"));
            })
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $warehouses = InventoryWarehouse::query()->orderBy('name')->get();

        return view('inventory.items.index', compact('items', 'warehouses'));
    }

    public function create(): View
    {
        $tracked = InventoryItem::query()->pluck('product_id')->all();
        $products = Product::query()->whereNotIn('id', $tracked)->where('is_active', true)->orderBy('name')->get();

        return view('inventory.items.create', compact('products'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

        $item = InventoryItem::create([
            'product_id' => $data['product_id'],
            'reorder_level' => $data['reorder_level'] ?? 0,
            'reorder_quantity' => $data['reorder_quantity'] ?? 0,
            'notes' => $data['notes'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return redirect()->route('inventory.items.show', $item)
            ->with('toasts', [['type' => 'success', 'message' => "Item \"{$item->product->name}\" added to inventory."]]);
    }

    public function show(InventoryItem $item): View
    {
        $item->load(['product', 'stock.warehouse', 'movements.reference']);
        $stockByWarehouse = $item->stock;

        return view('inventory.items.show', compact('item', 'stockByWarehouse'));
    }

    public function edit(InventoryItem $item): View
    {
        $item->load(['product', 'stock.warehouse']);

        return view('inventory.items.edit', compact('item'));
    }

    public function update(Request $request, InventoryItem $item): RedirectResponse
    {
        $data = $this->validateData($request);

        $item->update([
            'reorder_level' => $data['reorder_level'] ?? 0,
            'reorder_quantity' => $data['reorder_quantity'] ?? 0,
            'notes' => $data['notes'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return redirect()->route('inventory.items.show', $item)
            ->with('toasts', [['type' => 'success', 'message' => 'Item updated.']]);
    }

    public function adjustForm(InventoryItem $item): View
    {
        $item->load(['product', 'stock.warehouse']);
        $warehouses = InventoryWarehouse::query()->orderBy('name')->get();

        return view('inventory.items.adjust', compact('item', 'warehouses'));
    }

    public function adjust(Request $request, InventoryItem $item): RedirectResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', Rule::exists('inventory_warehouses', 'id')],
            'quantity' => ['required', 'numeric', 'not_in:0'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            InventoryLedger::adjust($item->id, $data['warehouse_id'], $data['quantity'], 'adjustment', null, $data['reason'] ?? 'Manual stock adjustment');
        } catch (\DomainException $e) {
            return back()->with('toasts', [['type' => 'error', 'message' => $e->getMessage()]]);
        }

        return redirect()->route('inventory.items.show', $item)
            ->with('toasts', [['type' => 'success', 'message' => 'Stock adjusted.']]);
    }

    public function destroy(InventoryItem $item): RedirectResponse
    {
        $name = $item->product?->name ?? 'Item';
        $item->delete();

        return redirect()->route('inventory.items.index')
            ->with('toasts', [['type' => 'success', 'message' => "Item \"{$name}\" removed from inventory."]]);
    }

    public function export(Request $request): StreamedResponse
    {
        $onHandSql = '(SELECT COALESCE(SUM(quantity), 0) FROM inventory_stock WHERE inventory_stock.item_id = inventory_items.id)';

        $items = InventoryItem::query()
            ->with(['product'])
            ->withSum('stock as on_hand', 'quantity')
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->when($request->filled('status'), fn ($q) => $q
                ->when($request->status === 'active', fn ($q) => $q->where('is_active', true))
                ->when($request->status === 'inactive', fn ($q) => $q->where('is_active', false)))
            ->when($request->filled('stock_status'), function ($q) use ($request, $onHandSql) {
                $q->when($request->stock_status === 'low', fn ($q) => $q->whereRaw("{$onHandSql} <= inventory_items.reorder_level AND inventory_items.reorder_level > 0"))
                    ->when($request->stock_status === 'out', fn ($q) => $q->whereRaw("{$onHandSql} <= 0"));
            })
            ->orderByDesc('id')
            ->get();

        return $this->streamCsv('inventory-items-'.now()->format('Y-m-d').'.csv', ['ID', 'Name', 'SKU', 'Unit', 'On hand', 'Reorder level', 'Reorder qty', 'Status'], $items->map(fn (InventoryItem $i) => [
            $i->id,
            $i->product?->name,
            $i->product?->sku,
            $i->product?->unit,
            number_format((float) $i->on_hand, 3),
            $i->reorder_level,
            $i->reorder_quantity,
            $i->is_active ? 'Active' : 'Inactive',
        ]));
    }

    private function validateData(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'product_id' => ['required', 'integer', Rule::exists('products', 'id'), Rule::unique('inventory_items', 'product_id')->ignore($ignoreId)],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
            'reorder_quantity' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['boolean'],
        ]);
    }
}
