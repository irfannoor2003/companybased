<x-app-layout :pageTitle="'Financial Reports'">
    <x-slot name="header">
        <x-page-header
            title="Financial Reports"
            description="Profit & loss, balance sheet, trial balance and general ledger computed from posted journal entries."
            icon="accounting"
        >
        </x-page-header>
    </x-slot>

    <x-card class="mb-6">
        <x-report-filter :action="route('reports.financial')" />
    </x-card>

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-stat-card label="Revenue" :value="money($summary['revenue'])" icon="money" tone="primary" hint="Period {{ $from }} to {{ $to }}" />
        <x-stat-card label="Expenses" :value="money($summary['expenses'])" icon="arrow-up" tone="danger" hint="Period {{ $from }} to {{ $to }}" />
        <x-stat-card label="Net profit" :value="money($summary['net'])" icon="chart" :tone="$summary['net'] >= 0 ? 'success' : 'danger'" hint="Revenue minus expenses" />
        <x-stat-card label="Total assets" :value="money($balanceSheet['assets'])" icon="banking" tone="info" hint="Position as of {{ $to }}" />
        <x-stat-card label="Total liabilities" :value="money($balanceSheet['liabilities'])" icon="report" tone="warning" hint="Position as of {{ $to }}" />
        <x-stat-card label="Total equity" :value="money($balanceSheet['equity'] + $balanceSheet['retained'])" icon="accounting" tone="success" hint="Equity and retained earnings" />
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
        <x-card title="Profit & Loss" description="{{ $from }} to {{ $to }}" :padding="false">
            <div class="table-wrap !border-0 !rounded-none">
                <table class="table-base">
                    <tbody>
                        <tr>
                            <td class="text-ink-soft">Revenue</td>
                            <td class="text-right font-medium text-ink">{{ money($summary['revenue']) }}</td>
                        </tr>
                        <tr>
                            <td class="text-ink-soft">Expenses</td>
                            <td class="text-right font-medium text-rose-600 dark:text-rose-400">{{ money($summary['expenses']) }}</td>
                        </tr>
                        <tr>
                            <td class="font-semibold text-ink">Net profit</td>
                            <td class="text-right font-bold {{ $summary['net'] >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' }}">
                                {{ money($summary['net']) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </x-card>

        <x-card title="Balance Sheet" description="As of {{ $to }}" :padding="false">
            <div class="table-wrap !border-0 !rounded-none">
                <table class="table-base">
                    <tbody>
                        <tr>
                            <td class="font-semibold text-ink">Total assets</td>
                            <td class="text-right font-medium text-ink">{{ money($balanceSheet['assets']) }}</td>
                        </tr>
                        <tr>
                            <td class="text-ink-soft">Total liabilities</td>
                            <td class="text-right font-medium text-ink">{{ money($balanceSheet['liabilities']) }}</td>
                        </tr>
                        <tr>
                            <td class="text-ink-soft">Equity</td>
                            <td class="text-right font-medium text-ink">{{ money($balanceSheet['equity']) }}</td>
                        </tr>
                        <tr>
                            <td class="text-ink-soft">Retained earnings</td>
                            <td class="text-right font-medium text-ink">{{ money($balanceSheet['retained']) }}</td>
                        </tr>
                        <tr>
                            <td class="font-semibold text-ink">Total liabilities & equity</td>
                            <td class="text-right font-bold text-primary">{{ money($balanceSheet['liabilities'] + $balanceSheet['equity'] + $balanceSheet['retained']) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </x-card>

        <x-card title="Trial Balance" description="As of {{ $to }}" :padding="false">
            @if ($trial['rows']->isEmpty())
                <x-empty-state icon="accounting" title="No posted entries" description="Post journal entries to see the trial balance." />
            @else
                <div class="table-wrap !border-0 !rounded-none">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th>Account</th>
                                <th>Type</th>
                                <th class="text-right">Debit</th>
                                <th class="text-right">Credit</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($trial['rows'] as $row)
                                <tr>
                                    <td>
                                        <span class="font-mono text-xs text-ink-faint">{{ $row['code'] }}</span>
                                        <span class="ml-1 font-medium text-ink">{{ $row['name'] }}</span>
                                    </td>
                                    <td class="text-ink-soft">{{ $row['type_label'] }}</td>
                                    <td class="text-right font-medium text-ink">{{ $row['debit'] !== null ? money($row['debit']) : '—' }}</td>
                                    <td class="text-right font-medium text-ink">{{ $row['credit'] !== null ? money($row['credit']) : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-line bg-surface-muted/50">
                                <td class="font-semibold text-ink" colspan="2">Totals</td>
                                <td class="text-right font-bold text-ink">{{ money($trial['total_debit']) }}</td>
                                <td class="text-right font-bold text-ink">{{ money($trial['total_credit']) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                @if (abs($trial['total_debit'] - $trial['total_credit']) > 0.01)
                    <p class="px-5 py-3 text-xs text-rose-600 dark:text-rose-400">
                        Out of balance by {{ money(abs($trial['total_debit'] - $trial['total_credit'])) }} — review the journal.
                    </p>
                @endif
            @endif
        </x-card>

        <x-card title="General Ledger" description="{{ $from }} to {{ $to }}" :padding="false">
            @if ($ledger->isEmpty())
                <x-empty-state icon="document" title="No posted entries" description="No journal entries match the selected period." />
            @else
                <div class="table-wrap !border-0 !rounded-none">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Entry</th>
                                <th>Account</th>
                                <th>Memo</th>
                                <th class="text-right">Debit</th>
                                <th class="text-right">Credit</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($ledger as $row)
                                <tr>
                                    <td class="whitespace-nowrap font-mono text-ink-soft">{{ $row->entry?->entry_date?->format('Y-m-d') ?? '—' }}</td>
                                    <td class="whitespace-nowrap font-mono text-ink-soft">{{ $row->entry?->number }}</td>
                                    <td class="font-medium text-ink">{{ $row->account?->code }} · {{ $row->account?->name }}</td>
                                    <td class="text-ink-soft">{{ $row->memo ?: $row->entry?->description }}</td>
                                    <td class="text-right font-medium text-ink">{{ (float) $row->debit > 0 ? money($row->debit) : '—' }}</td>
                                    <td class="text-right font-medium text-ink">{{ (float) $row->credit > 0 ? money($row->credit) : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-card>
    </div>
</x-app-layout>