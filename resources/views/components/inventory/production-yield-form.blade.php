@props([
    'order',
    'action',
])

{{--
    Report what a run actually produced, and complete the order in one submission.

    The columns, the service and the endpoint all existed, but nothing in the
    interface could write them: `quantity_good`, `quantity_scrap` and the per-component
    `quantity_scrapped` had to be hand-POSTed to the status endpoint. So the numbers
    that decide a manufactured item's cost were arithmetic nobody could perform in the
    application.

    Every field is optional. An order completed with the sheet left blank falls back to
    consuming the required quantity and producing the ordered quantity, which is
    exactly what completion did before reporting existed. Reporting must never become a
    new thing that can block finishing a job.
--}}

@php
    $locked = in_array($order->status, ['completed', 'cancelled'], true);

    $goodDefault = (float) old('quantity_good', $order->quantity);
    $scrapDefault = (float) old('quantity_scrap', 0);

    $serverYield = ($goodDefault + $scrapDefault) > 0
        ? round(($goodDefault / ($goodDefault + $scrapDefault)) * 100, 1)
        : 0.0;

    $oldActuals = (array) old('actuals', []);

    $lineRows = $order->items->values()->map(function ($line, $index) use ($oldActuals) {
        $row = (array) ($oldActuals[$index] ?? []);

        return [
            'component_item_id' => $line->component_item_id,
            'name' => $line->componentItem?->product?->name ?? 'Component',
            'sku' => $line->componentItem?->product?->sku ?? '',
            'required' => (float) $line->quantity_required,
            'quantity_used' => $row['quantity_used'] ?? (float) $line->quantity_required,
            'quantity_scrapped' => $row['quantity_scrapped'] ?? (float) ($line->quantity_scrapped ?? 0),
            'scrap_reason' => $row['scrap_reason'] ?? $line->scrap_reason,
        ];
    });
@endphp

<form method="POST" action="{{ $action }}" class="space-y-5"
      x-data="{ good: {{ $goodDefault }}, scrap: {{ $scrapDefault }} }">
    @csrf
    @method('PATCH')

    <input type="hidden" name="status" value="completed">

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-input
            name="quantity_good"
            label="Good output"
            type="number"
            step="0.001"
            min="0"
            x-model.number="good"
            :disabled="$locked"
        />
        <x-input
            name="quantity_scrap"
            label="Scrapped output"
            type="number"
            step="0.001"
            min="0"
            x-model.number="scrap"
            :disabled="$locked"
        />

        <div>
            <span class="mb-1 block text-sm font-medium text-ink-soft">Yield</span>
            <div class="flex h-[38px] items-center rounded-lg border border-line bg-surface-muted/50 px-3 text-sm font-medium text-ink">
                {{-- Server-rendered value is what shows before Alpine boots. --}}
                <span x-text="(good + scrap) > 0 ? ((good / (good + scrap)) * 100).toFixed(1) : '0.0'">{{ $serverYield }}</span>%
            </div>
            <p class="mt-1 text-xs text-ink-faint">Good units as a share of everything produced.</p>
        </div>
    </div>

    @if ($order->items->isNotEmpty())
        <div>
            <p class="mb-1 text-sm font-medium text-ink-soft">Actual component consumption</p>
            <p class="mb-3 text-xs text-ink-faint">
                Blank quantities fall back to the required figure. Scrap is absorbed into
                the cost of the good output rather than expensed separately — the material
                really was consumed to make them — and surfaces on Production Variance.
            </p>

            <div class="table-wrap">
                <table class="table-base">
                    <thead>
                        <tr>
                            <th>Component</th>
                            <th class="text-right">Required</th>
                            <th class="w-32 text-right">Used</th>
                            <th class="w-32 text-right">Scrapped</th>
                            <th>Scrap reason</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($lineRows as $index => $row)
                            <tr>
                                <td>
                                    <span class="font-medium text-ink">{{ $row['name'] }}</span>
                                    @if ($row['sku'])
                                        <span class="ml-1 text-xs text-ink-faint">{{ $row['sku'] }}</span>
                                    @endif
                                    <input type="hidden" name="actuals[{{ $index }}][component_item_id]" value="{{ $row['component_item_id'] }}">
                                </td>
                                <td class="text-right text-ink-soft">{{ $row['required'] }}</td>
                                <td>
                                    <input type="number" step="0.001" min="0"
                                           name="actuals[{{ $index }}][quantity_used]"
                                           value="{{ $row['quantity_used'] }}"
                                           class="input text-right" @disabled($locked)>
                                </td>
                                <td>
                                    <input type="number" step="0.001" min="0"
                                           name="actuals[{{ $index }}][quantity_scrapped]"
                                           value="{{ $row['quantity_scrapped'] }}"
                                           class="input text-right" @disabled($locked)>
                                </td>
                                <td>
                                    <input type="text" maxlength="255"
                                           name="actuals[{{ $index }}][scrap_reason]"
                                           value="{{ $row['scrap_reason'] }}"
                                           class="input" placeholder="e.g. cracked at final inspection"
                                           @disabled($locked)>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @if ($locked)
        <p class="rounded-lg border border-line bg-surface-muted/50 px-4 py-3 text-sm text-ink-faint">
            This order is {{ $order->status }}, so the run has already been settled and the
            reported figures can no longer be changed.
        </p>
    @else
        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-line pt-4">
            <p class="text-xs text-ink-faint">
                Completing consumes the components above and receives the good output into stock.
            </p>
            <x-button type="submit" icon="check">Complete and report yield</x-button>
        </div>
    @endif
</form>
