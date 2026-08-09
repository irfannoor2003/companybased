<x-app-layout :pageTitle="'Visits'">
    <x-slot name="header">
        <x-page-header title="Visits" description="Plan and track field customer visits with pit-stops." icon="visits">
            <x-slot name="actions">
                @if (auth()->user()->can('visits.visits.export'))
                    <x-export route="visits.export" />
                @endif
                @if (auth()->user()->can('visits.visits.create'))
                    <x-button href="{{ route('visits.create') }}" icon="plus">New visit</x-button>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    @include('visits._tabs')

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-4">
        <x-stat-card label="Pending" :value="$pending" icon="clock" tone="warning" />
        <x-stat-card label="In progress" :value="$started" icon="zap" tone="info" />
        <x-stat-card label="Completed" :value="$completed" icon="check" tone="success" />
        <x-stat-card label="Scheduled today" :value="$today" icon="calendar" tone="primary" />
    </div>

    <div class="mt-6">
        <x-card :padding="false">
            <form method="GET" action="{{ route('visits.index') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
                <div class="min-w-[220px] flex-1">
                    <x-input name="search" label="Search" placeholder="Visit number, purpose, customer…" leadingIcon="search"
                        value="{{ request('search') }}" size="sm" />
                </div>
                <div class="w-40">
                    <x-select name="status" label="Status" size="sm">
                        <option value="">Any status</option>
                        @foreach (\App\Models\Visit::statusOptions() as $status)
                            <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
                        @endforeach
                    </x-select>
                </div>
                <div class="w-48">
                    <x-select name="sales_rep_id" label="Sales rep" size="sm">
                        <option value="">All reps</option>
                        @foreach ($salesReps as $rep)
                            <option value="{{ $rep->id }}" @selected(request('sales_rep_id') == $rep->id)>{{ $rep->fullName() }}</option>
                        @endforeach
                    </x-select>
                </div>
                <div class="w-40">
                    <x-input name="from" label="From" type="date" value="{{ request('from') }}" size="sm" />
                </div>
                <div class="w-40">
                    <x-input name="to" label="To" type="date" value="{{ request('to') }}" size="sm" />
                </div>
                <div class="flex gap-2">
                    <x-button type="submit" size="sm" icon="filter">Filter</x-button>
                    @if (request()->hasAny(['search', 'status', 'sales_rep_id', 'from', 'to']))
                        <x-button href="{{ route('visits.index') }}" variant="ghost" size="sm">Clear</x-button>
                    @endif
                </div>
            </form>

            @if ($visits->isEmpty())
                <x-empty-state icon="visits" title="No visits" description="Schedule a customer visit to get started." />
            @else
                <div class="table-wrap !border-0 !rounded-none">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th>Visit</th>
                                <th>Customer</th>
                                <th>Sales rep</th>
                                <th>Scheduled</th>
                                <th class="text-right">Pit stops</th>
                                <th>Status</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($visits as $visit)
                                <tr>
                                    <td>
                                        <a href="{{ route('visits.show', $visit) }}" class="font-mono font-medium text-ink hover:text-primary">
                                            {{ $visit->visit_number }}
                                        </a>
                                        @if ($visit->purpose)
                                            <span class="block text-xs text-ink-faint">{{ $visit->purpose }}</span>
                                        @endif
                                    </td>
                                    <td class="text-ink-soft">{{ $visit->customer?->company_name ?: '—' }}</td>
                                    <td class="text-ink-soft">{{ $visit->salesRep?->fullName() ?: '—' }}</td>
                                    <td class="text-ink-soft">{{ $visit->scheduled_at?->format('Y-m-d') ?: '—' }}</td>
                                    <td class="text-right text-ink-soft">{{ $visit->pit_stops_count }}</td>
                                    <td><x-visits.status-badge :status="$visit->status" /></td>
                                    <td class="text-right">
                                        <a href="{{ route('visits.show', $visit) }}" class="btn-ghost btn-icon btn-sm" title="View">
                                            <x-icon name="eye" class="size-4" />
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($visits->hasPages())
                <div class="px-5 py-4">
                    {{ $visits->links() }}
                </div>
            @endif
        </x-card>
    </div>
</x-app-layout>