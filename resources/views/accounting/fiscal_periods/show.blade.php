<x-app-layout :pageTitle="$fiscalPeriod->label()">
    <x-slot name="header">
        <x-page-header :title="$fiscalPeriod->label()"
                       :description="$fiscalPeriod->start_date->format('j M Y').' to '.$fiscalPeriod->end_date->format('j M Y')"
                       icon="calendar">
            <x-slot name="actions">
                @include('accounting.fiscal_periods.partials.actions', ['period' => $fiscalPeriod])
            </x-slot>
        </x-page-header>
    </x-slot>

    <x-toasts />

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-card title="Entries dated in this period">
                <x-table :empty="true"
                         empty-title="No entries in this period"
                         empty-description="Nothing has been posted with a date inside these dates.">
                    <x-slot name="thead">
                        <tr>
                            <th>Number</th>
                            <th>Date</th>
                            <th>Description</th>
                            <th>Status</th>
                            <th class="text-right">Lines</th>
                        </tr>
                    </x-slot>

                    @foreach ($entries as $entry)
                        <tr>
                            <td>
                                <a href="{{ route('accounting.journal.show', $entry) }}"
                                   class="font-medium text-primary-ink">{{ $entry->number }}</a>
                            </td>
                            <td>{{ $entry->entry_date->format('Y-m-d') }}</td>
                            <td>{{ $entry->description ?: '—' }}</td>
                            <td><x-accounting.status-badge :status="$entry->status" /></td>
                            <td class="text-right">{{ $entry->items_count }}</td>
                        </tr>
                    @endforeach
                </x-table>

                @if ($entries->hasPages())
                    <div class="border-t border-line px-5 py-3">{{ $entries->links() }}</div>
                @endif
            </x-card>
        </div>

        <div class="space-y-6">
            <x-card title="Period">
                <dl class="space-y-4 text-sm">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-ink-faint">Status</dt>
                        <dd class="mt-1">@include('accounting.fiscal_periods.partials.status', ['period' => $fiscalPeriod])</dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-ink-faint">
                            Refuses entries on or before
                        </dt>
                        <dd class="mt-1">{{ $fiscalPeriod->date_lock?->format('Y-m-d') ?? 'No restriction' }}</dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-ink-faint">Closed by</dt>
                        <dd class="mt-1">
                            {{ $fiscalPeriod->closer?->name ?? '—' }}
                            @if ($fiscalPeriod->closed_at)
                                <span class="text-ink-faint">on {{ $fiscalPeriod->closed_at->format('j M Y H:i') }}</span>
                            @endif
                        </dd>
                    </div>

                    @if ($fiscalPeriod->lock_reason)
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-ink-faint">Reason</dt>
                            <dd class="mt-1">{{ $fiscalPeriod->lock_reason }}</dd>
                        </div>
                    @endif
                </dl>
            </x-card>

            <x-card title="Posted totals">
                <div class="grid grid-cols-3 gap-4">
                    <x-stat-card label="Debits" :value="number_format($totals['debits'], 2)" />
                    <x-stat-card label="Credits" :value="number_format($totals['credits'], 2)" />
                    <x-stat-card label="Out of balance"
                                 :value="$totals['debits'] === $totals['credits'] ? 'No' : number_format(abs($totals['debits'] - $totals['credits']), 2)"
                                 :tone="$totals['debits'] === $totals['credits'] ? 'success' : 'primary'" />
                </div>
            </x-card>
        </div>
    </div>
</x-app-layout>
