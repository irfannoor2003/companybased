<x-app-layout :pageTitle="'Fiscal periods'">
    <x-slot name="header">
        <x-page-header title="Fiscal periods"
                       description="Period close is the gate on posting. Once locked, a period cannot be reopened."
                       icon="calendar">
            <x-slot name="actions">
                @if (auth()->user()->can('accounting.fiscal_periods.create'))
                    <x-button href="{{ route('accounting.fiscal_periods.create') }}" icon="plus">New period</x-button>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    {{-- Toasts are the app's convention for a one-off outcome of an action. --}}
    <x-toasts />

    <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
        <x-stat-card label="Open" :value="$summary['open']" tone="success" />
        <x-stat-card label="Closed" :value="$summary['closed']" tone="warning" />
        <x-stat-card label="Locked" :value="$summary['locked']" tone="primary" />
        <x-stat-card label="Entries outside any period"
                     :value="$summary['unassigned']"
                     hint="Allowed, but not governed by a close." />
    </div>

    <x-card>
        <x-table :empty="true"
                 empty-title="No periods defined"
                 empty-description="Entries post normally until a period covers their date.">
            <x-slot name="thead">
                <tr>
                    <th>Period</th>
                    <th>Dates</th>
                    <th>Status</th>
                    <th>Posted entries</th>
                    <th>Refuses entries on or before</th>
                    <th class="text-right">Actions</th>
                </tr>
            </x-slot>

            @forelse ($periods as $period)
                <tr>
                    <td>
                        <a href="{{ route('accounting.fiscal_periods.show', $period) }}"
                           class="font-medium text-primary-ink">{{ $period->label() }}</a>
                    </td>
                    <td>{{ $period->start_date->format('Y-m-d') }} to {{ $period->end_date->format('Y-m-d') }}</td>
                    <td>@include('accounting.fiscal_periods.partials.status', ['period' => $period])</td>
                    <td>{{ $period->posted_entries_count }}</td>
                    <td>{{ $period->date_lock?->format('Y-m-d') ?? 'No restriction' }}</td>
                    <td class="text-right">
                        @include('accounting.fiscal_periods.partials.actions', ['period' => $period])
                    </td>
                </tr>
            @empty
                {{-- The component renders the empty state from the props above. --}}
            @endforelse
        </x-table>

        @if ($periods->hasPages())
            <div class="border-t border-line px-5 py-3">{{ $periods->links() }}</div>
        @endif
    </x-card>
</x-app-layout>
