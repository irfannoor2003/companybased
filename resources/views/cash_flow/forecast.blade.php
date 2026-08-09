<x-app-layout :pageTitle="'Cash flow forecast'">
    <x-slot name="header">
        <x-page-header title="Cash flow forecast" description="Expected receipts and payments bucketed by due date relative to today." icon="chart">
            <x-slot name="actions">
                @if (auth()->user()->can('cash_flow.forecast.export'))
                    <x-export route="cash_flow.forecast.export" />
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    @include('cash_flow._tabs')

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <x-stat-card label="Cash & bank balance" :value="money($cashBalance)" icon="banking" tone="primary" hint="Starting position before scheduled movements" />
        <x-stat-card label="Scheduled net over 90 days" :value="money($inflowBuckets->sum() - $outflowBuckets->sum())" icon="activity" tone="{{ $inflowBuckets->sum() - $outflowBuckets->sum() >= 0 ? 'success' : 'danger' }}" hint="Expected inflows minus outflows" />
    </div>

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach (['0-30', '31-60', '61-90', '90+'] as $bucket)
            <x-card :title="'Due in '.$bucket.' days'">
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-ink-faint">Expected in</dt>
                        <dd class="font-medium text-emerald-600 dark:text-emerald-400">+{{ money($inflowBuckets[$bucket]) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-ink-faint">Expected out</dt>
                        <dd class="font-medium text-rose-600 dark:text-rose-400">−{{ money($outflowBuckets[$bucket]) }}</dd>
                    </div>
                    <div class="flex justify-between border-t border-line pt-2">
                        <dt class="font-semibold text-ink">Net</dt>
                        <dd class="font-semibold {{ $netBuckets[$bucket] >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' }}">
                            {{ $netBuckets[$bucket] >= 0 ? '+' : '−' }}{{ money(abs($netBuckets[$bucket])) }}
                        </dd>
                    </div>
                </dl>
            </x-card>
        @endforeach
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
        <x-card title="Expected receipts" description="Outstanding customer invoices" :padding="false">
            @if ($receivablesDue->isEmpty())
                <x-empty-state icon="arrow-down" title="Nothing due" description="No outstanding customer invoices." />
            @else
                <div class="table-wrap !border-0 !rounded-none">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th>Invoice</th>
                                <th>Customer</th>
                                <th>Due date</th>
                                <th class="text-right">Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($receivablesDue as $item)
                                <tr>
                                    <td><span class="font-mono text-xs font-medium text-primary">{{ $item['number'] }}</span></td>
                                    <td class="text-ink">{{ $item['customer'] ?? '—' }}</td>
                                    <td class="text-ink-soft">{{ $item['due_date']?->format('Y-m-d') }}</td>
                                    <td class="text-right font-medium text-emerald-600 dark:text-emerald-400">{{ money($item['balance']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-card>

        <x-card title="Expected payments" description="Outstanding supplier invoices" :padding="false">
            @if ($payablesDue->isEmpty())
                <x-empty-state icon="arrow-up" title="Nothing due" description="No outstanding supplier invoices." />
            @else
                <div class="table-wrap !border-0 !rounded-none">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th>Invoice</th>
                                <th>Supplier</th>
                                <th>Due date</th>
                                <th class="text-right">Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($payablesDue as $item)
                                <tr>
                                    <td><span class="font-mono text-xs font-medium text-primary">{{ $item['number'] }}</span></td>
                                    <td class="text-ink">{{ $item['supplier'] ?? '—' }}</td>
                                    <td class="text-ink-soft">{{ $item['due_date']?->format('Y-m-d') }}</td>
                                    <td class="text-right font-medium text-rose-600 dark:text-rose-400">{{ money($item['balance']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-card>
    </div>
</x-app-layout>
