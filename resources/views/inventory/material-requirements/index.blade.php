<x-app-layout :pageTitle="'Material Requirements'">
    <x-slot name="header">
        <x-page-header title="Material Requirements"
            description="What is short, and what is already covered, from confirmed sales demand." icon="zap" />
    </x-slot>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-card :padding="false" class="p-4">
            <div class="text-xs font-medium uppercase tracking-wide text-ink-soft">Items planned</div>
            <div class="mt-1 text-2xl font-semibold text-ink">{{ $summary['items'] }}</div>
        </x-card>

        <x-card :padding="false" class="p-4">
            <div class="text-xs font-medium uppercase tracking-wide text-ink-soft">Components</div>
            <div class="mt-1 text-2xl font-semibold text-ink">{{ $summary['components'] }}</div>
        </x-card>

        <x-card :padding="false" class="p-4">
            <div class="text-xs font-medium uppercase tracking-wide text-ink-soft">Shortages</div>
            <div class="mt-1 text-2xl font-semibold {{ $summary['shortages'] > 0 ? 'text-rose-600' : 'text-emerald-600' }}">
                {{ $summary['shortages'] }}
            </div>
        </x-card>

        <x-card :padding="false" class="p-4">
            <div class="text-xs font-medium uppercase tracking-wide text-ink-soft">Purchase value</div>
            <div class="mt-1 text-2xl font-semibold text-ink">{{ $summary['purchase_value'] }}</div>
        </x-card>
    </div>

    <x-card :padding="false">
        <form method="GET" action="{{ route('inventory.material-requirements.index') }}"
            class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
            <div class="w-48">
                <x-input type="date" name="horizon" label="Demand up to" size="sm" value="{{ $horizon->toDateString() }}" />
            </div>
            <div class="w-56">
                <x-select name="warehouse" label="Warehouse" size="sm">
                    <option value="">Any warehouse</option>
                    @foreach ($warehouses as $option)
                        <option value="{{ $option->id }}" @selected($warehouse?->id === $option->id)>{{ $option->name }}</option>
                    @endforeach
                </x-select>
            </div>
            <div class="flex gap-2">
                <x-button type="submit" size="sm" icon="filter">Plan</x-button>
                <x-button href="{{ route('inventory.material-requirements.index') }}" variant="ghost" size="sm">Clear</x-button>
            </div>
        </form>

        @if ($plan === [])
            <x-empty-state icon="zap" title="Nothing to plan"
                description="No confirmed sales orders demand a manufactured item before {{ $horizon->toDateString() }}. Confirm a sales order, or bill of materials, to see requirements here." />
        @else
            @foreach ($plan as $entry)
                <div class="border-b border-line last:border-b-0">
                    <div class="flex flex-wrap items-baseline justify-between gap-2 bg-surface px-5 py-3">
                        <div>
                            <span class="font-medium text-ink">{{ $entry['item']->product?->name }}</span>
                            @if ($entry['bom'])
                                <span class="ml-2 text-xs text-ink-soft">BOM v{{ $entry['bom']->version }}</span>
                            @endif
                        </div>
                        <div class="text-sm text-ink-soft">
                            Demand <span class="font-medium text-ink">{{ rtrim(rtrim(number_format($entry['quantity'], 3), '0'), '.') }}</span>
                        </div>
                    </div>

                    <div class="table-wrap !border-0 !rounded-none">
                        <table class="table-base">
                            <thead>
                                <tr>
                                    <th>Component</th>
                                    <th class="text-right">Required</th>
                                    <th class="text-right">On hand</th>
                                    <th class="text-right">On order</th>
                                    <th class="text-right">Committed</th>
                                    <th class="text-right">Net</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($entry['components'] as $line)
                                    <tr class="{{ $line['is_short'] ? 'bg-rose-50/40' : '' }}">
                                        <td class="font-medium text-ink">{{ $line['product']?->name }}</td>
                                        <td class="text-right text-ink-soft">{{ rtrim(rtrim(number_format($line['gross'], 3), '0'), '.') }}</td>
                                        <td class="text-right text-ink-soft">{{ rtrim(rtrim(number_format($line['on_hand'], 3), '0'), '.') }}</td>
                                        <td class="text-right text-ink-soft">{{ rtrim(rtrim(number_format($line['on_order'], 3), '0'), '.') }}</td>
                                        <td class="text-right text-ink-soft">{{ rtrim(rtrim(number_format($line['committed'], 3), '0'), '.') }}</td>
                                        <td class="text-right">
                                            @if ($line['is_short'])
                                                <span class="font-semibold text-rose-600">{{ rtrim(rtrim(number_format($line['net'], 3), '0'), '.') }}</span>
                                            @elseif ($line['below_reorder'])
                                                <span class="text-xs text-amber-600">Covered, below reorder level</span>
                                            @else
                                                <span class="text-emerald-600">Covered</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endforeach
        @endif
    </x-card>
</x-app-layout>