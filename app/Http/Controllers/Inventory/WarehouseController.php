<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\InventoryWarehouse;
use App\Support\ExportsCsv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WarehouseController extends Controller
{
    use ExportsCsv;

    public function index(Request $request): View
    {
        $warehouses = InventoryWarehouse::query()
            ->withCount('stock')
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->when($request->filled('status'), fn ($q) => $q
                ->when($request->status === 'active', fn ($q) => $q->where('is_active', true))
                ->when($request->status === 'inactive', fn ($q) => $q->where('is_active', false)))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('inventory.warehouses.index', compact('warehouses'));
    }

    public function create(): View
    {
        return view('inventory.warehouses.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

        $warehouse = InventoryWarehouse::create([
            'name' => $data['name'],
            'code' => strtoupper($data['code']),
            'address' => $data['address'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return redirect()->route('inventory.warehouses.index')
            ->with('toasts', [['type' => 'success', 'message' => "Warehouse \"{$warehouse->name}\" created."]]);
    }

    public function edit(InventoryWarehouse $warehouse): View
    {
        return view('inventory.warehouses.edit', compact('warehouse'));
    }

    public function update(Request $request, InventoryWarehouse $warehouse): RedirectResponse
    {
        $data = $this->validateData($request, $warehouse->id);

        $warehouse->update([
            'name' => $data['name'],
            'code' => strtoupper($data['code']),
            'address' => $data['address'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return back()->with('toasts', [['type' => 'success', 'message' => "Warehouse \"{$warehouse->name}\" updated."]]);
    }

    public function destroy(InventoryWarehouse $warehouse): RedirectResponse
    {
        if ($warehouse->stock()->where('quantity', '>', 0)->exists()) {
            return back()->with('toasts', [['type' => 'error', 'message' => 'This warehouse still holds stock and cannot be deleted.']]);
        }

        $name = $warehouse->name;
        $warehouse->delete();

        return redirect()->route('inventory.warehouses.index')
            ->with('toasts', [['type' => 'success', 'message' => "Warehouse \"{$name}\" deleted."]]);
    }

    public function export(Request $request): StreamedResponse
    {
        $warehouses = InventoryWarehouse::query()
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->orderBy('name')
            ->get();

        return $this->streamCsv('warehouses-'.now()->format('Y-m-d').'.csv', ['Code', 'Name', 'Address', 'Status'], $warehouses->map(fn (InventoryWarehouse $w) => [
            $w->code,
            $w->name,
            $w->address,
            $w->is_active ? 'Active' : 'Inactive',
        ]));
    }

    private function validateData(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:20', Rule::unique('inventory_warehouses', 'code')->ignore($ignoreId)],
            'address' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ]);
    }
}
