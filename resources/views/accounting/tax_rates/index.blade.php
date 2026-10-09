<x-app-layout :pageTitle="'Tax rates'">
    <x-slot name="header">
        <x-page-header title="Tax rates"
                       description="Rates in force by date. A rate is superseded, never edited, so issued documents keep the rate they were raised under."
                       icon="tax">
            <x-slot name="actions">
                @can('accounting.tax_rates.manage')
                    <x-button href="{{ route('accounting.tax_rates.create') }}" icon="plus">New rate</x-button>
                @endcan
            </x-slot>
        </x-page-header>
    </x-slot>

    <x-toasts />

    {{-- What the engine will actually apply today. A user adding goods at 20%
         needs to know whether 20% is even the current rate. --}}
    @if ($inForce)
        <x-alert type="info" class="mb-4">
            Currently applied to sales documents:
            <span class="font-medium text-ink">{{ $inForce->name }} &mdash; {{ $inForce->label() }}</span>
            @if ($inForce->is_compound)
                (charged on the tax as well as the net)
            @endif
        </x-alert>
    @else
        <x-alert type="warning" class="mb-4">
            <strong>No sales tax rate is in force today.</strong>
            Documents will not charge tax, and the editor will say so rather than
            implying the goods are exempt.
        </x-alert>
    @endif

    <x-card>
        <x-table>
            <x-slot name="thead">
                <tr>
                    <th>Rate</th>
                    <th class="text-right">Percentage</th>
                    <th>Applies to</th>
                    <th>In force</th>
                    <th>Posts to</th>
                    <th>State</th>
                    @can('accounting.tax_rates.manage')
                        <th class="text-right">Actions</th>
                    @endcan
                </tr>
            </x-slot>

            @forelse ($rates as $rate)
                <tr>
                    <td>
                        <div class="font-medium text-ink">{{ $rate->name }}</div>
                        <div class="font-mono text-xs text-ink-faint">{{ $rate->code }}</div>
                    </td>
                    <td class="text-right font-medium text-ink">{{ $rate->label() }}</td>
                    <td class="text-ink-soft">{{ ucfirst($rate->applies_to) }}</td>
                    <td class="text-ink-soft">
                        {{ $rate->effective_from->format('j M Y') }}
                        &ndash;
                        {{ $rate->effective_to?->format('j M Y') ?? 'open' }}
                        @if ($rate->priority > 0)
                            <div class="text-xs text-ink-faint">priority {{ $rate->priority }}</div>
                        @endif
                    </td>
                    <td class="text-ink-soft">
                        {{ $rate->account?->code ?? '—' }}
                        @if ($rate->is_compound)
                            <div class="text-xs text-ink-faint">compound</div>
                        @endif
                        @if ($rate->threshold_amount)
                            <div class="text-xs text-ink-faint">above {{ money($rate->threshold_amount) }}</div>
                        @endif
                    </td>
                    <td>
                        @if ($rate->is_exempt)
                            <x-badge color="warning" dot>Exempt</x-badge>
                        @elseif (! $rate->is_active)
                            <x-badge color="neutral" dot>Disabled</x-badge>
                        @elseif ($rate->isInForceOn(today()))
                            <x-badge color="success" dot>In force</x-badge>
                        @else
                            <x-badge color="neutral" dot>Superseded</x-badge>
                        @endif
                    </td>

                    @can('accounting.tax_rates.manage')
                        <td class="text-right">
                            <div class="inline-flex justify-end gap-2">
                                <x-button href="{{ route('accounting.tax_rates.edit', $rate) }}"
                                          variant="ghost" size="sm">Supersede</x-button>

                                <form method="POST" action="{{ route('accounting.tax_rates.toggle', $rate) }}">
                                    @csrf
                                    <x-button type="submit" variant="ghost" size="sm">
                                        {{ $rate->is_active ? 'Disable' : 'Enable' }}
                                    </x-button>
                                </form>

                                @can('accounting.tax_rates.delete')
                                    @if (! $rate->isInForceOn(today()) && $rate->effective_from->isFuture())
                                        <form method="POST" action="{{ route('accounting.tax_rates.destroy', $rate) }}"
                                              onsubmit="return confirm('Delete rate {{ $rate->code }}?')">
                                            @csrf
                                            @method('DELETE')
                                            <x-button type="submit" variant="danger-secondary" size="sm" icon="trash" />
                                        </form>
                                    @endif
                                @endcan
                            </div>
                        </td>
                    @endcan
                </tr>
            @empty
                <tr>
                    <td colspan="7">
                        <x-empty-state icon="tax"
                                       title="No tax rates configured"
                                       description="Add a rate and it will apply to documents raised from its effective date onwards." />
                    </td>
                </tr>
            @endforelse
        </x-table>

        @if ($rates->hasPages())
            <div class="border-t border-line px-5 py-3">{{ $rates->links() }}</div>
        @endif
    </x-card>
</x-app-layout>