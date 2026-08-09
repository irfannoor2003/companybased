<x-app-layout :pageTitle="'POS Shifts'">
    <x-slot name="header">
        <x-page-header title="Shifts" description="Cashier shifts with opening cash and closing counts." icon="clock">
            <x-slot name="actions">
                @if (auth()->user()->can('pos.shifts.export'))
                    <x-export route="pos.shifts.export" />
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    @include('pos._tabs')

    @if ($openShift)
        <div class="mt-6">
            <x-card>
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h3 class="text-sm font-semibold text-ink">Shift {{ $openShift->shift_number }} is open</h3>
                        <p class="mt-0.5 text-sm text-ink-faint">Opened {{ $openShift->opened_at?->format('Y-m-d H:i') }} · Opening cash {{ money($openShift->opening_cash) }} · Sales {{ money($openShift->salesTotal()) }}</p>
                    </div>
                    @if (auth()->user()->can('pos.shifts.close'))
                        <form method="POST" action="{{ route('pos.shifts.close', $openShift) }}" class="flex items-end gap-3">
                            @csrf
                            <x-input name="counted_cash" label="Counted cash" type="number" step="0.01" min="0" size="sm" required placeholder="0.00" />
                            <x-button type="submit" variant="secondary" icon="check">Close shift</x-button>
                        </form>
                    @endif
                </div>
            </x-card>
        </div>
    @endif

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div>
            <x-card>
                <h3 class="text-sm font-semibold text-ink">Open new shift</h3>
                <form method="POST" action="{{ route('pos.shifts.open') }}" class="mt-3 space-y-3">
                    @csrf
                    <x-input name="opening_cash" label="Opening cash" type="number" step="0.01" min="0" required placeholder="0.00" :error="$errors->first('opening_cash')" />
                    <x-input name="notes" label="Notes" placeholder="Optional" />
                    <x-button type="submit" icon="play">Open shift</x-button>
                </form>
            </x-card>
        </div>
    </div>

    <div class="mt-6">
        <x-card :padding="false">
            <form method="GET" action="{{ route('pos.shifts.index') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
                <div class="w-40">
                    <x-select name="status" label="Status" size="sm">
                        <option value="">Any</option>
                        @foreach (\App\Models\PosShift::statusOptions() as $status)
                            <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
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
                    @if (request()->hasAny(['status', 'from', 'to']))
                        <x-button href="{{ route('pos.shifts.index') }}" variant="ghost" size="sm">Clear</x-button>
                    @endif
                </div>
            </form>

            @if ($shifts->isEmpty())
                <x-empty-state icon="clock" title="No shifts" description="Open a shift to start selling." />
            @else
                <div class="table-wrap !border-0 !rounded-none">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th>Shift</th>
                                <th>Opened by</th>
                                <th>Opened at</th>
                                <th class="text-right">Opening cash</th>
                                <th class="text-right">Sales</th>
                                <th class="text-right">Expected</th>
                                <th class="text-right">Counted</th>
                                <th class="text-right">Variance</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($shifts as $shift)
                                <tr>
                                    <td class="font-mono font-medium text-ink">{{ $shift->shift_number }}</td>
                                    <td class="text-ink-soft">{{ $shift->opener?->name ?: '—' }}</td>
                                    <td class="text-ink-soft">{{ $shift->opened_at?->format('Y-m-d H:i') }}</td>
                                    <td class="text-right text-ink-soft">{{ money($shift->opening_cash) }}</td>
                                    <td class="text-right text-ink-soft">{{ money($shift->salesTotal()) }}</td>
                                    <td class="text-right text-ink-soft">{{ $shift->expected_cash !== null ? money($shift->expected_cash) : '—' }}</td>
                                    <td class="text-right text-ink-soft">{{ $shift->counted_cash !== null ? money($shift->counted_cash) : '—' }}</td>
                                    <td class="text-right font-medium {{ ($shift->variance ?? 0) >= 0 ? 'text-success' : 'text-danger' }}">{{ $shift->variance !== null ? money($shift->variance) : '—' }}</td>
                                    <td>
                                        <x-badge :color="$shift->isOpen() ? 'success' : 'neutral'">{{ ucfirst($shift->status) }}</x-badge>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($shifts->hasPages())
                <div class="px-5 py-4">
                    {{ $shifts->links() }}
                </div>
            @endif
        </x-card>
    </div>
</x-app-layout>