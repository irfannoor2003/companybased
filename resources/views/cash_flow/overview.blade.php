<x-app-layout :pageTitle="'Cash flow overview'">
    <x-slot name="header">
        <x-page-header title="Cash flow" description="Position, movements and outlook across the business." icon="cashflow">
            <x-slot name="actions">
                @if (auth()->user()->can('cash_flow.reports.export'))
                    <x-button href="{{ route('cash_flow.reports') }}" variant="secondary" icon="report">Cash flow statement</x-button>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    @include('cash_flow._tabs')

        <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-stat-card label="Cash & bank balance" :value="money($cashBalance)" icon="banking" tone="primary" />
            <x-stat-card label="Receivables (owed to us)" :value="money($receivables)" icon="arrow-down" tone="info" hint="Outstanding customer invoices" />
            <x-stat-card label="Payables (we owe)" :value="money($payables)" icon="arrow-up" tone="warning" hint="Outstanding supplier invoices" />
            <x-stat-card label="Net position" :value="money($cashBalance + $receivables - $payables)" icon="report" tone="{{ $cashBalance + $receivables - $payables >= 0 ? 'success' : 'danger' }}" />
        </div>

        <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
            <x-stat-card label="Cash in this month" :value="money($inflowsMonth)" icon="arrow-down" tone="success" />
            <x-stat-card label="Cash out this month" :value="money($outflowsMonth)" icon="arrow-up" tone="danger" />
            <x-stat-card label="Net this month" :value="money($inflowsMonth - $outflowsMonth)" icon="activity" tone="{{ $inflowsMonth - $outflowsMonth >= 0 ? 'success' : 'danger' }}" />
        </div>

        <div class="mt-6">
            <x-card title="Recent activity" :padding="false">
                @if ($activity->isEmpty())
                    <x-empty-state icon="activity" title="No activity" description="Cash movements will appear here." />
                @else
                    <div class="table-wrap !border-0 !rounded-none">
                        <table class="table-base">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Source</th>
                                    <th>Reference</th>
                                    <th>Description</th>
                                    <th class="text-right">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($activity as $row)
                                    <tr>
                                        <td class="text-ink-soft">{{ $row['date'] instanceof \DateTimeInterface ? $row['date']->format('Y-m-d') : $row['date'] }}</td>
                                        <td class="text-ink">{{ $row['source'] }}</td>
                                        <td><span class="font-mono text-xs text-ink-soft">{{ $row['ref'] ?? '—' }}</span></td>
                                        <td class="text-ink-soft">{{ $row['desc'] ?? '—' }}</td>
                                        <td class="text-right font-medium {{ $row['amount'] < 0 ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400' }}">
                                            {{ $row['amount'] < 0 ? '−' : '+' }}{{ money(abs($row['amount'])) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-card>
        </div>
    </x-app-layout>
