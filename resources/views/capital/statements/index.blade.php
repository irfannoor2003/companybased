<x-app-layout :pageTitle="'Capital Statements'">
    <x-slot name="header">
        <x-page-header title="Statements" description="Per-owner statement of contributions and drawings with running balance." icon="report">
            <x-slot name="actions">
                @if (auth()->user()->can('capital_accounts.statements.export'))
                    <x-export route="capital.statements.export" />
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    @include('capital._tabs')

    <div class="mt-6">
        <x-card :padding="false">
            <form method="GET" action="{{ route('capital.statements.index') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
                <div class="w-52">
                    <x-select name="party" label="Owner" size="sm">
                        <option value="">All owners</option>
                        @foreach ($parties as $name)
                            <option value="{{ $name }}" @selected($party === $name)>{{ $name }}</option>
                        @endforeach
                    </x-select>
                </div>
                <div class="w-40">
                    <x-input name="from" label="From" type="date" value="{{ $from }}" size="sm" />
                </div>
                <div class="w-40">
                    <x-input name="to" label="To" type="date" value="{{ $to }}" size="sm" />
                </div>
                <div class="flex gap-2">
                    <x-button type="submit" size="sm" icon="filter">Generate</x-button>
                    @if ($party || $from || $to)
                        <x-button href="{{ route('capital.statements.index') }}" variant="ghost" size="sm">Clear</x-button>
                    @endif
                </div>
                <span class="ml-auto text-sm text-ink-faint">{{ $party ?: 'All owners' }} · {{ $from ?: 'start' }} → {{ $to ?: 'today' }}</span>
            </form>

            @if ($rows->isEmpty())
                <x-empty-state icon="report" title="No activity" description="Select filters to generate a statement." />
            @else
                <div class="table-wrap !border-0 !rounded-none">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Reference</th>
                                <th>Owner</th>
                                <th>Method</th>
                                <th class="text-right">Amount</th>
                                <th class="text-right">Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr>
                                    <td class="text-ink-soft">{{ $row['date']->format('Y-m-d') }}</td>
                                    <td>
                                        <x-badge :color="$row['type'] === 'contribution' ? 'success' : 'danger'">
                                            {{ $row['type'] === 'contribution' ? 'Contribution' : 'Drawing' }}
                                        </x-badge>
                                    </td>
                                    <td class="font-mono text-ink">{{ $row['reference'] }}</td>
                                    <td class="text-ink-soft">{{ $row['party'] }}</td>
                                    <td class="text-ink-faint">{{ $row['method'] ? ucfirst(str_replace('_', ' ', $row['method'])) : '—' }}</td>
                                    <td class="text-right {{ $row['amount'] >= 0 ? 'text-success' : 'text-danger' }}">
                                        {{ $row['amount'] >= 0 ? '+' : '−' }}{{ money(abs($row['amount'])) }}
                                    </td>
                                    <td class="text-right font-medium text-ink">{{ money($row['balance']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-line">
                                <td colspan="5" class="text-right font-semibold text-ink">Closing balance</td>
                                <td class="text-right font-semibold {{ $totals['equity'] >= 0 ? 'text-success' : 'text-danger' }}">{{ money($totals['equity']) }}</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </x-card>
    </div>
</x-app-layout>