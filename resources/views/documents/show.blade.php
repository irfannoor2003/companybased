<x-app-layout :pageTitle="$title.' '.$number">
    <x-slot name="header">
        <x-page-header :title="$title.' '.$number" :description="$billTo['name']" icon="document">
            <x-slot name="actions">
                <div class="print:hidden">
                    @can($viewPermission)
                        <x-button :href="$pdfRoute" variant="secondary" icon="download" target="_blank" rel="noopener">Export PDF</x-button>
                    @endcan
                    <button type="button" onclick="window.print()" class="btn-ghost btn-icon btn-sm" title="Print">
                        <x-icon name="printer" class="size-4" />
                    </button>
                    @can($viewPermission)
                        <x-button :href="$editRoute" variant="secondary" icon="edit">Edit</x-button>
                    @endcan
                </div>
                <x-button :href="$backRoute" variant="secondary" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    @php
        $dateRow = $meta[0] ?? null;
        $statusRow = collect($meta)->firstWhere('label', 'Status');
        $metaExtras = array_values(array_filter($meta, fn ($row) => ($row['show'] ?? true) && ($dateRow ? $row['label'] !== $dateRow['label'] : true) && $row['label'] !== 'Status'));
    @endphp

    <div class="mt-6 max-w-4xl">
        <div class="document">
            <div class="flex justify-between border-b border-line pb-4 mb-6">
                <div>
                    @if (settings('branding.logo'))
                        <img src="{{ Storage::url(settings('branding.logo')) }}" alt="{{ company_name() }}" class="h-10">
                    @else
                        <span class="text-xl font-bold text-ink">{{ company_name() }}</span>
                    @endif
                    <p class="text-sm text-ink-faint mt-1">{{ settings('company.address') ?: '' }}</p>
                    <p class="text-sm text-ink-faint">{{ settings('company.email') ?: '' }}</p>
                </div>
                <div class="text-right">
                    <h2 class="text-2xl font-bold text-ink">{{ $title }}</h2>
                    <p class="text-sm text-ink-soft mt-1">{{ $number }}</p>
                    <p class="text-sm text-ink-faint">{{ $dateRow['label'] }}: {{ $dateRow['value'] ?? '—' }}</p>
                    <p class="text-sm text-ink-faint">Status: {{ $statusRow ? $statusRow['value'] : '—' }}</p>
                </div>
            </div>

            <div class="mb-6">
                <h3 class="text-sm font-semibold text-ink-faint uppercase mb-2">{{ $billTo['heading'] }}</h3>
                @if ($billTo['name'])
                    <p class="font-medium text-ink">{{ $billTo['name'] }}</p>
                @endif
                @if ($billTo['contact'])
                    <p class="text-sm text-ink-soft">{{ $billTo['contact'] }}</p>
                @endif
                @if ($billTo['address'])
                    <p class="text-sm text-ink-soft">{{ $billTo['address'] }}</p>
                @endif
                @if ($billTo['tax'])
                    <p class="text-sm text-ink-soft">Tax: {{ $billTo['tax'] }}</p>
                @endif
                @if ($billTo['code'])
                    <p class="text-sm text-ink-soft">Code: {{ $billTo['code'] }}</p>
                @endif
            </div>

            @if (! empty($metaExtras))
                <div class="grid grid-cols-2 gap-x-6 gap-y-2 mb-6">
                    @foreach ($metaExtras as $row)
                        <div>
                            <span class="text-sm text-ink-faint">{{ $row['label'] }}: </span>
                            <span class="text-sm text-ink">{{ $row['value'] ?: '—' }}</span>
                        </div>
                    @endforeach
                </div>
            @endif

            @if (! empty($columns))
                <table class="table-base mb-6">
                    <thead>
                        <tr>
                            @foreach ($columns as $column)
                                <th class="{{ $column['align'] === 'right' ? 'text-right' : ($column['align'] === 'center' ? 'text-center' : '') }}">{{ $column['label'] }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <td class="text-sm text-ink">{{ $loop->iteration }}</td>
                                <td class="text-sm text-ink">{{ $row['description'] }}</td>
                                <td class="text-right text-sm text-ink">{{ number_format((float) $row['qty'], 2) }}</td>
                                @if ($hasPricing)
                                    <td class="text-right text-sm text-ink">{{ money($row['unit_price'], $currency) }}</td>
                                    <td class="text-right text-sm text-ink">{{ money($row['tax'], $currency) }}</td>
                                    <td class="text-right text-sm font-medium text-ink">{{ money($row['total'], $currency) }}</td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            @if ($hasPricing && ! empty($totals))
                <div class="flex justify-end mb-6">
                    <div class="w-full max-w-xs space-y-1.5">
                        @foreach ($totals as $total)
                            <div class="flex justify-between {{ $total['total'] ? 'border-t border-line pt-1.5 font-semibold' : '' }}">
                                <span class="text-ink-faint">{{ $total['label'] }}</span>
                                <span class="text-ink">{{ money($total['value'], $currency) }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($notes)
                <div class="mb-6">
                    <h3 class="text-sm font-semibold text-ink-faint uppercase mb-2">Notes</h3>
                    <p class="text-sm text-ink">{{ $notes }}</p>
                </div>
            @endif

            <div class="border-t border-line pt-4 text-center text-xs text-ink-faint">
                @if (! $hasPricing)
                    <p>Prepared for shipment.</p>
                @else
                    <p>Thank you for your business.</p>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
