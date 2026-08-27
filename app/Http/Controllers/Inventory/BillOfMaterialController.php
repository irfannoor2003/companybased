<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\InventoryBillOfMaterial;
use App\Models\InventoryItem;
use App\Support\ExportsCsv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BillOfMaterialController extends Controller
{
    use ExportsCsv;

    public function index(Request $request): View
    {
        $billOfMaterials = InventoryBillOfMaterial::query()
            ->with(['item.product'])
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('inventory.bill_of_materials.index', compact('billOfMaterials'));
    }

    public function create(): View
    {
        $items = InventoryItem::query()->with('product')->where('is_active', true)->orderBy('id')->get();

        return view('inventory.bill_of_materials.create', compact('items'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

        $bom = InventoryBillOfMaterial::create([
            'name' => $data['name'],
            'item_id' => $data['item_id'],
            'version' => $data['version'] ?? '1',
            'status' => $data['status'],
            'note' => $data['note'] ?? null,
        ]);

        $this->syncItems($bom, $request->input('items', []));

        return redirect()->route('inventory.bill_of_materials.index')
            ->with('toasts', [['type' => 'success', 'message' => "Bill of materials \"{$bom->name}\" created."]]);
    }

    public function edit(InventoryBillOfMaterial $billOfMaterial): View
    {
        $billOfMaterial->load(['item.product', 'items.componentItem.product']);
        $items = InventoryItem::query()->with('product')->where('is_active', true)->orderBy('id')->get();

        return view('inventory.bill_of_materials.edit', compact('billOfMaterial', 'items'));
    }

    public function update(Request $request, InventoryBillOfMaterial $billOfMaterial): RedirectResponse
    {
        $data = $this->validateData($request);

        $billOfMaterial->update([
            'name' => $data['name'],
            'item_id' => $data['item_id'],
            'version' => $data['version'] ?? '1',
            'status' => $data['status'],
            'note' => $data['note'] ?? null,
        ]);

        $this->syncItems($billOfMaterial, $request->input('items', []));

        return back()->with('toasts', [['type' => 'success', 'message' => "Bill of materials \"{$billOfMaterial->name}\" updated."]]);
    }

    public function destroy(InventoryBillOfMaterial $billOfMaterial): RedirectResponse
    {
        $name = $billOfMaterial->name;
        $billOfMaterial->delete();

        return redirect()->route('inventory.bill_of_materials.index')
            ->with('toasts', [['type' => 'success', 'message' => "Bill of materials \"{$name}\" deleted."]]);
    }

    public function export(Request $request): StreamedResponse
    {
        $billOfMaterials = InventoryBillOfMaterial::query()
            ->with(['item.product'])
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->latest('id')
            ->get();

        return $this->streamCsv('bill-of-materials-'.now()->format('Y-m-d').'.csv', ['Name', 'Finished item', 'Version', 'Components', 'Status', 'Note'], $billOfMaterials->map(fn (InventoryBillOfMaterial $b) => [
            $b->name,
            $b->item?->product?->name,
            $b->version,
            $b->items_count ?? $b->items()->count(),
            ucfirst($b->status),
            $b->note,
        ]));
    }

    private function syncItems(InventoryBillOfMaterial $bom, array $rawItems): void
    {
        $cleaned = [];

        foreach ($rawItems as $item) {
            $quantity = (float) ($item['quantity'] ?? 0);

            if (empty($item['item_id']) || $quantity <= 0) {
                continue;
            }

            if ((int) $item['item_id'] === (int) $bom->item_id) {
                continue;
            }

            $cleaned[] = [
                'component_item_id' => $item['item_id'],
                'quantity' => round($quantity, 3),
                'wastage_percent' => (float) ($item['wastage_percent'] ?? 0),
            ];
        }

        $bom->items()->delete();
        $bom->items()->createMany($cleaned);
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'item_id' => ['required', 'integer', Rule::exists('inventory_items', 'id')],
            'version' => ['nullable', 'string', 'max:40'],
            'status' => ['required', Rule::in(InventoryBillOfMaterial::statusOptions())],
            'note' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'integer', Rule::exists('inventory_items', 'id')],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.wastage_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);
    }
}
