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

        // Chart data: stock valuation trend by month
        $stockChartData = $this->stockValuationChartData($from, $to);

        // Chart data: on-hand by warehouse
        $warehouseChartData = $this->onHandByWarehouseChartData();

        $stockChartJson = json_encode([
            'labels' => $stockChartData['labels'],
            'datasets' => [[
                'label' => 'Stock Value',
                'data' => $stockChartData['values'],
                'borderColor' => '#38bdf8',
                'backgroundColor' => 'rgba(56, 191, 248, 0.1)',
                'fill' => true,
                'tension' => 0.4,
            ]],
        ]);

        $stockOptionsJson = json_encode([
            'responsive' => true,
            'plugins' => [
                'legend' => ['position' => 'bottom'],
                'title' => ['display' => true, 'text' => 'Stock Valuation by Month'],
            ],
            'scales' => [
                'y' => ['title' => ['display' => true, 'text' => 'Value'], 'beginAtZero' => true],
            ],
        ]);

        $warehouseChartJson = json_encode([
            'labels' => $warehouseChartData['labels'],
            'datasets' => [[
                'label' => 'On Hand',
                'data' => $warehouseChartData['values'],
                'backgroundColor' => 'rgba(34, 197, 94, 0.6)',
            ]],
        ]);

        $warehouseOptionsJson = json_encode([
            'responsive' => true,
            'plugins' => [
                'legend' => ['display' => false],
                'title' => ['display' => true, 'text' => 'On-Hand Stock by Warehouse'],
            ],
            'scales' => [
                'y' => ['title' => ['display' => true, 'text' => 'Quantity'], 'beginAtZero' => true],
            ],
        ]);

        return view('reports.inventory', compact('from', 'to', 'stockRows', 'valuation', 'reorder', 'movements', 'stats', 'stockChartData', 'warehouseChartData', 'stockChartJson', 'stockOptionsJson', 'warehouseChartJson', 'warehouseOptionsJson'));
    }

    /**
     * Monthly valuation data for chart.
     *
     * `inventory_stock` is a current snapshot, so it cannot answer "what was the
     * value in March". Each point is therefore rebuilt from the movement ledger:
     * the running sum of `quantity_change` up to the end of that month, valued at
     * the product's current cost price. Valuing at today's cost is an
     * approximation, but it is the only cost available in the schema — there is
     * no movement-level cost column — and it is consistent across the series.
     */
    private function stockValuationChartData(string $from, string $to): array
    {
        $costByItem = InventoryItem::query()
            ->join('products', 'products.id', '=', 'inventory_items.product_id')
            ->whereNotNull('products.cost_price')
            ->pluck('products.cost_price', 'inventory_items.id')
            ->map(fn ($cost) => (float) $cost);

        $months = [];
        $values = [];

        for ($m = strtotime($from); $m <= strtotime($to); $m = strtotime('+1 month', $m)) {
            $monthEnd = date('Y-m-t 23:59:59', $m);

            // Running on-hand per item as at the close of this month.
            $quantityByItem = InventoryMovement::query()
                ->where('created_at', '<=', $monthEnd)
                ->groupBy('item_id')
                ->selectRaw('item_id, SUM(quantity_change) as qty')
                ->pluck('qty', 'item_id');

            $value = 0.0;

            foreach ($quantityByItem as $itemId => $qty) {
                if (! isset($costByItem[$itemId])) {
                    continue;
                }

                $value += (float) $qty * $costByItem[$itemId];
            }

            $months[] = date('M Y', $m);
            $values[] = round($value, 2);
        }

        return [
            'labels' => $months,
            'values' => $values,
        ];
    }

    /**
     * On-hand quantity by warehouse chart data.
     */
    private function onHandByWarehouseChartData(): array
    {
        $warehouses = InventoryWarehouse::withCount('stock as total_stock')
            ->get()
            ->keyBy('id');

        $data = [];
        foreach ($warehouses as $warehouse) {
            $total = (float) $warehouse->total_stock;
            if ($total > 0) {
                $data[] = [
                    'label' => $warehouse->name,
                    'value' => $total,
                ];
            }
        }

        usort($data, fn ($a, $b) => $b['value'] <=> $a['value']);

        return [
            'labels' => array_column($data, 'label'),
            'values' => array_column($data, 'value'),
            'max' => $data !== [] ? (float) max(array_column($data, 'value', 'label')) : 0,
        ];
    }

    /**
     * Valuation per item: on-hand quantity across warehouses × product cost.
     *
     * @return Collection<int, array<string, mixed>>
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
