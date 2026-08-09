<x-app-layout :pageTitle="'Till Reconciliation'">
    <x-slot name="header">
        <x-page-header title="Till Reconciliation" description="Recorded shift cash counts and variances." icon="report">
            <x-slot name="actions">
                @if (auth()->user()->can('pos.till_reconciliation.export'))
                    <x-export route="pos.reconciliations.export" />
                @endif
                @if (auth()->user()->can('pos.till_reconciliation.create'))
                    <x-button href="{{ route('pos.reconciliations.create') }}" icon="plus">Record count</x-button>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    @include('pos._tabs')

    <div class="mt-6">
        <x-card :padding="false">
            <form method="GET" action="{{ route('pos.reconciliations.index') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
                <div class="w-40">
                    <x-input name="from" label="From" type="date" value="{{ request('from') }}" size="sm" />
                </div>
                <div class="w-40">
                    <x-input name="to" label="To" type="date" value="{{ request('to') }}" size="sm" />
                </div>
                <div class="flex gap-2">
                    <x-button type="submit" size="sm" icon="filter">Filter</x-button>
                    @if (request()->hasAny(['from', 'to']))
                        <x-button href="{{ route('pos.reconciliations.index') }}" variant="ghost" size="sm">Clear</x-button>
                    @endif
                </div>
            </form>

            @if ($reconciliations->isEmpty())
                <x-empty-state icon="report" title="No reconciliations" description="Record counted cash against a closed shift to reconcile the till." />
            @else
                <div class="table-wrap !border-0 !rounded-none">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th>Shift</th>
                                <th>Reconciled at</th>
                                <th>By</th>
                                <th class="text-right">Opening</th>
                                <th class="text-right">Sales</th>
                                <th class="text-right">Expected</th>
                                <th class="text-right">Counted</th>
                                <th class="text-right">Variance</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($reconciliations as $recon)
                                <tr>
                                    <td class="font-mono font-medium text-ink">{{ $recon->shift?->shift_number ?: '—' }}</td>
                                    <td class="text-ink-soft">{{ $recon->reconciled_at?->format('Y-m-d H:i') }}</td>
                                    <td class="text-ink-soft">{{ $recon->reconciler?->name ?: '—' }}</td>
                                    <td class="text-right text-ink-soft">{{ money($recon->opening_cash) }}</td>
                                    <td class="text-right text-ink-soft">{{ money($recon->sales_total) }}</td>
                                    <td class="text-right text-ink-soft">{{ money($recon->expected_cash) }}</td>
                                    <td class="text-right text-ink-soft">{{ money($recon->counted_cash) }}</td>
                                    <td class="text-right font-medium {{ (float) $recon->variance >= 0 ? 'text-success' : 'text-danger' }}">{{ money($recon->variance) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($reconciliations->hasPages())
                <div class="px-5 py-4">
                    {{ $reconciliations->links() }}
                </div>
            @endif
        </x-card>
    </div>
</x-app-layout>