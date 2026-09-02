<x-app-layout :pageTitle="'Inventory Reports'">
    <x-slot name="header">
        <x-page-header
            title="Inventory Reports"
            description="Stock on hand, valuation at product cost, stock movements and reorder alerts."
            icon="inventory"
        >
        </x-page-header>
    </x-slot>

    <x-card class="mb-6">
        <x-report-filter :action="route('reports.inventory')" />
    </x-card>

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-stat-card label="Stock keeping units" :value="number_format($stats['skus'])" icon="inventory" tone="primary" />
        <x-stat-card label="Units on hand" :value="number_format($stats['units'], 3, '.', ',')" icon="package" tone="info" />
        <x-stat-card label="Stock value" :value="money($stats['value'])" icon="money" tone="success" hint="Valued at product cost" />
        <x-stat-card label="Warehouses" :value="number_format($stats['warehouses'])" icon="building" tone="neutral" />
        <x-stat-card label="Movements" :value="number_format($stats['movements'])" icon="activity" tone="warning" hint="Period {{ $from }} to {{ $to }}" />
        <x-stat-card label="Reorder alerts" :value="number_format($stats['low'])" icon="chart" tone="danger" hint="At or below reorder level" />
    </div>

    @php
        $movementLabels = [
            'initial' => 'Initial stock',
            'adjustment' => 'Adjustment',
            'transfer_in' => 'Transfer in',
            'transfer_out' => 'Transfer out',
            'write_off' => 'Write-off',
            'production_in' => 'Production in',
            'production_out' => 'Production out',
            'purchase_in' => 'Purchase receipt',
            'incoming_shipment' => 'Incoming shipment',
        ];
    @endphp

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
        <x-card title="Stock on Hand" description="Per warehouse" :padding="false">
            @if ($stockRows->isEmpty())
                <x-empty-state icon="inventory" title="No stock" description="Receive or adjust stock to see on-hand levels." />
            @else
                <div class="table-wrap !border-0 !rounded-none">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>SKU</th>
                                <th>Warehouse</th>
                                <th class="text-right">On hand</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($stockRows as $row)
                                <tr>
                                    <td class="font-medium text-ink">{{ $row->item?->product?->name ?? '—' }}</td>
                                    <td class="font-mono text-ink-soft">{{ $row->item?->product?->sku ?? '—' }}</td>
                                    <td class="text-ink-soft">{{ $row->warehouse?->name ?? '—' }}</td>
                                    <td class="text-right font-medium text-ink">{{ number_format((float) $row->quantity, 3, '.', ',') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="border-t border-line px-4 py-3">
                    {{ $stockRows->links() }}
                </div>
            @endif
        </x-card>

        <x-card title="Inventory Valuation" description="On hand × product cost" :padding="false">
            @if ($valuation->isEmpty())
                <x-empty-state icon="money" title="No valuables" description="Configure cost prices on products with stock to see valuation." />
            @else
                <div class="table-wrap !border-0 !rounded-none">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>SKU</th>
                                <th class="text-right">On hand</th>
                                <th class="text-right">Cost</th>
                                <th class="text-right">Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($valuation as $row)
                                <tr>
                                    <td class="font-medium text-ink">{{ $row['name'] }}</td>
                                    <td class="font-mono text-ink-soft">{{ $row['sku'] }}</td>
                                    <td class="text-right text-ink-soft">{{ number_format((float) $row['qty'], 3, '.', ',') }}</td>
                                    <td class="text-right text-ink-soft">{{ money($row['cost']) }}</td>
                                    <td class="text-right font-medium text-ink">{{ money($row['value']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-line bg-surface-muted/50">
                                <td class="font-semibold text-ink" colspan="4">Total stock value</td>
                                <td class="text-right font-bold text-ink">{{ money($valuation->sum('value')) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </x-card>

        <x-card title="Stock Movements" description="{{ $from }} to {{ $to }}" :padding="false">
            @if ($movements->isEmpty())
                <x-empty-state icon="activity" title="No movements" description="No stock movements match the selected period." />
            @else
                <div class="table-wrap !border-0 !rounded-none">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Product</th>
                                <th>Warehouse</th>
                                <th>Type</th>
                                <th class="text-right">Change</th>
                                <th>Note</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($movements as $mvt)
                                <tr>
                                    <td class="whitespace-nowrap font-mono text-ink-soft">{{ $mvt->created_at?->format('Y-m-d H:i') }}</td>
                                    <td class="font-medium text-ink">{{ $mvt->item?->product?->name ?? '—' }}</td>
                                    <td class="text-ink-soft">{{ $mvt->warehouse?->name ?? '—' }}</td>
                                    <td>
                                        <x-badge :value="$movementLabels[$mvt->movement_type] ?? ucfirst($mvt->movement_type)" :color="$mvt->quantity_change >= 0 ? 'info' : 'neutral'" />
                                    </td>
                                    <td class="text-right font-medium {{ (float) $mvt->quantity_change >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' }}">
                                        {{ (float) $mvt->quantity_change >= 0 ? '+' : '' }}{{ number_format((float) $mvt->quantity_change, 3, '.', ',') }}
                                    </td>
                                    <td class="text-ink-soft">{{ $mvt->note }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="border-t border-line px-4 py-3">
                    {{ $movements->links() }}
                </div>
            @endif
        </x-card>

        <x-card title="Reorder Alerts" description="Items at or below their reorder level" :padding="false">
            @if ($reorder->isEmpty())
                <x-empty-state icon="chart" title="All stocked" description="No items are at or below their reorder level." />
            @else
                <div class="table-wrap !border-0 !rounded-none">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>SKU</th>
                                <th class="text-right">On hand</th>
                                <th class="text-right">Reorder level</th>
                                <th class="text-right">Suggested order</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($reorder as $row)
                                <tr>
                                    <td class="font-medium text-ink">{{ $row['name'] }}</td>
                                    <td class="font-mono text-ink-soft">{{ $row['sku'] }}</td>
                                    <td class="text-right font-medium text-rose-600 dark:text-rose-400">{{ number_format($row['on_hand'], 3, '.', ',') }}</td>
                                    <td class="text-right text-ink-soft">{{ number_format($row['reorder_level'], 3, '.', ',') }}</td>
                                    <td class="text-right text-ink-soft">{{ $row['reorder_quantity'] > 0 ? number_format($row['reorder_quantity'], 3, '.', ',') : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-card>
    </div>
</x-app-layout>