<?php

namespace App\Http\Controllers;

use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\InventoryWarehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Read-only inventory reporting: on-hand levels per warehouse, valuation at
 * product cost, the stock ledger and reorder alerts.
 */
class InventoryReportController extends Controller
{
    public function index(Request $request): View
    {
        $from = $request->get('from') ?: now()->startOfMonth()->toDateString();
        $to = $request->get('to') ?: now()->toDateString();

        $stockRows = InventoryStock::query()
            ->with(['item.product', 'warehouse'])
            ->orderBy('warehouse_id')
            ->orderBy('item_id')
            ->paginate(30)
            ->withQueryString();

        $valuation = $this->valuation();

        $reorder = InventoryItem::query()
            ->with(['product', 'stock'])
            ->get()
            ->filter(function (InventoryItem $item) {
                $level = (float) $item->reorder_level;

                return $level > 0 && $this->onHand($item) <= $level;
            })
            ->map(fn (InventoryItem $item) => [
                'name' => $item->product?->name,
                'sku' => $item->product?->sku,
                'on_hand' => $this->onHand($item),
                'reorder_level' => (float) $item->reorder_level,
                'reorder_quantity' => (float) $item->reorder_quantity,
            ])
            ->sortBy('on_hand')
            ->values();

        $movements = InventoryMovement::query()
            ->with(['item.product', 'warehouse'])
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
            ->latest('created_at')
            ->paginate(30)
            ->withQueryString();

        $stats = [
            'skus' => InventoryItem::query()->count(),
            'units' => round((float) InventoryStock::query()->sum('quantity'), 3),
            'value' => round((float) $valuation->sum('value'), 2),
            'warehouses' => InventoryWarehouse::query()->count(),
            'movements' => $movements->total(),
            'low' => $reorder->count(),
        ];

        return view('reports.inventory', compact('from', 'to', 'stockRows', 'valuation', 'reorder', 'movements', 'stats'));
    }

    /**
     * Valuation per item: on-hand quantity across warehouses × product cost.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function valuation(): Collection
    {
        return InventoryStock::query()
            ->with(['item.product'])
            ->get()
            ->groupBy('item_id')
            ->map(function (Collection $rows) {
                $item = $rows->first()?->item;
                $qty = round((float) $rows->sum('quantity'), 3);
                $cost = round((float) ($item?->product?->cost_price ?? 0), 2);

                return [
                    'name' => $item?->product?->name ?? '—',
                    'sku' => $item?->product?->sku ?? '—',
                    'qty' => $qty,
                    'cost' => $cost,
                    'value' => round($qty * $cost, 2),
                ];
            })
            ->sortByDesc('value')
            ->values();
    }

    private function onHand(InventoryItem $item): float
    {
        return round((float) $item->stock->sum('quantity'), 3);
    }
}