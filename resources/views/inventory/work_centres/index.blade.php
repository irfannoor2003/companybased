<x-app-layout :pageTitle="'Work centres'">
    <x-slot name="header">
        <x-page-header title="Work centres" description="Machines, benches and lines — and how loaded each one is."
                       icon="settings">
            <x-slot name="actions">
                @if (auth()->user()->can('inventory.work_centres.view_capacity'))
                    <x-button href="{{ route('inventory.work_centres.capacity') }}" variant="secondary" icon="chart">
                        Capacity
                    </x-button>
                @endif
                @if (auth()->user()->can('inventory.work_centres.export'))
                    <x-export route="inventory.work_centres.export" />
                @endif
                @if (auth()->user()->can('inventory.work_centres.create'))
                    <x-button href="{{ route('inventory.work_centres.create') }}" icon="plus">New work centre</x-button>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    <x-card :padding="false">
        <form method="GET" action="{{ route('inventory.work_centres.index') }}"
              class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
            <div class="min-w-[220px] flex-1">
                <x-input name="search" label="Search" placeholder="Name or code" leadingIcon="search"
                    value="{{ request('search') }}" size="sm" />
            </div>
            <div class="w-40">
                <x-select name="type" label="Type" size="sm">
                    <option value="">Any type</option>
                    @foreach ($types as $type)
                        <option value="{{ $type }}" @selected(request('type') === $type)>{{ ucfirst($type) }}</option>
                    @endforeach
                </x-select>
            </div>
            <div class="flex gap-2">
                <x-button type="submit" size="sm" icon="filter">Filter</x-button>
                @if (request()->hasAny(['search', 'type']))
                    <x-button href="{{ route('inventory.work_centres.index') }}" variant="ghost" size="sm">Clear</x-button>
                @endif
            </div>
        </form>

        @if ($centres->isEmpty())
            <x-empty-state icon="settings" title="No work centres"
                           description="Add the machines and benches work happens at, then route products through them to plan capacity." />
        @else
            <div class="table-wrap !border-0 !rounded-none">
                <table class="table-base">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Name</th>
                            <th>Type</th>
                            <th class="text-right">Hourly rate</th>
                            <th class="text-right">Minutes / day</th>
                            <th>Location</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($centres as $centre)
                            <tr>
                                <td class="font-mono text-xs text-ink-soft">{{ $centre->code }}</td>
                                <td class="font-medium text-ink">{{ $centre->name }}</td>
                                <td class="text-ink-soft">{{ ucfirst($centre->type) }}</td>
                                <td class="text-right text-ink">{{ number_format((float) $centre->hourly_rate, 2) }}</td>
                                <td class="text-right text-ink">
                                    {{ $centre->capacity_minutes_per_day === null ? '—' : (int) $centre->capacity_minutes_per_day }}
                                </td>
                                <td class="text-ink-soft">{{ $centre->location ?: '—' }}</td>
                                <td>
                                    @if ($centre->is_active)
                                        <x-badge tone="success" label="Active" />
                                    @else
                                        {{-- A retired centre keeps its history but takes no
                                             new work, so it is not deleted out from under
                                             completed operations. --}}
                                        <x-badge tone="default" label="Retired" />
                                    @endif
                                </td>
                                <td class="text-right">
                                    @if (auth()->user()->can('inventory.work_centres.edit'))
                                        <x-button :href="route('inventory.work_centres.edit', $centre)"
                                                  variant="ghost" size="sm" icon="edit">Edit</x-button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="px-5 py-3">{{ $centres->links() }}</div>
        @endif
    </x-card>
</x-app-layout>