<x-app-layout :pageTitle="'Work centre capacity'">
    <x-slot name="header">
        <x-page-header title="Work centre capacity" description="Committed minutes against the minutes available each day."
                       icon="chart">
            <x-slot name="actions">
                <x-button href="{{ route('inventory.work_centres.index') }}" variant="secondary" icon="arrow-left">
                    All work centres
                </x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <form method="GET" action="{{ route('inventory.work_centres.capacity') }}"
          class="mb-4 flex flex-wrap items-end gap-3">
        <div class="w-48">
            <x-input type="date" name="date" label="Date" :value="$date->toDateString()" size="sm" />
        </div>
        <x-button type="submit" size="sm" icon="filter">Show</x-button>
    </form>

    @if (empty($rows))
        <x-card>
            <x-empty-state icon="chart" title="No work centres"
                           description="Add a work centre and its daily capacity, then route products through it." />
        </x-card>
    @else
        {{-- Overloaded centres sort to the top, which is the whole reason this report
             exists: the machine that is already double-booked should be the first
             thing on the screen, not found by reading every row. --}}
        <x-card :padding="false">
            <div class="table-wrap !border-0 !rounded-none">
                <table class="table-base">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Name</th>
                            <th>Type</th>
                            <th class="text-right">Operations</th>
                            <th class="text-right">Committed</th>
                            <th class="text-right">Capacity</th>
                            <th class="text-right">Remaining</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            @php $centre = $row['work_centre']; @endphp
                            <tr @class(['bg-rose-50/60 dark:bg-rose-500/10' => $row['over_capacity']])>
                                <td class="font-mono text-xs text-ink-soft">{{ $centre->code }}</td>
                                <td class="font-medium text-ink">
                                    {{ $centre->name }}
                                    @if ($row['over_capacity'])
                                        <x-badge tone="danger" label="Over capacity" />
                                    @endif
                                </td>
                                <td class="text-ink-soft">{{ ucfirst($centre->type) }}</td>
                                <td class="text-right text-ink">{{ $row['operations'] }}</td>
                                <td class="text-right text-ink">{{ number_format($row['committed'], 0) }}</td>
                                <td class="text-right text-ink-soft">
                                    {{ $row['capacity'] === null ? '—' : number_format($row['capacity'], 0) }}
                                </td>
                                <td class="text-right">
                                    @if ($row['remaining'] === null)
                                        <span class="text-ink-faint">Unconstrained</span>
                                    @elseif ($row['over_capacity'])
                                        <span class="font-medium text-rose-600 dark:text-rose-400">
                                            {{ number_format(abs($row['remaining']), 0) }} over
                                        </span>
                                    @else
                                        <span class="text-ink">{{ number_format($row['remaining'], 0) }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>

        <p class="mt-3 text-xs text-ink-faint">
            Committed time counts the greater of recorded and planned minutes for every
            operation still open on an order due to finish on this date. A centre with
            no daily capacity is shown as unconstrained rather than assumed infinite.
        </p>
    @endif
</x-app-layout>