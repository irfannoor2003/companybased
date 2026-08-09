<x-app-layout :pageTitle="'Cash flow statement'">
    <x-slot name="header">
        <x-page-header title="Cash flow statement" description="Opening balance, net movement and closing cash & bank for the period." icon="report">
            <x-slot name="actions">
                @if (auth()->user()->can('cash_flow.reports.export'))
                    <x-export route="cash_flow.reports.export" />
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    @include('cash_flow._tabs')

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-stat-card label="Opening balance" :value="money($opening)" icon="banking" tone="primary" hint="Position at the start of the period" />
        <x-stat-card label="Total inflows" :value="money($inflows)" icon="arrow-down" tone="success" hint="Receipts and deposits" />
        <x-stat-card label="Total outflows" :value="money($outflows)" icon="arrow-up" tone="danger" hint="Payments and withdrawals" />
    </div>

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <x-stat-card label="Net change" :value="money($net)" icon="activity" tone="{{ $net >= 0 ? 'success' : 'danger' }}" />
        <x-stat-card label="Closing balance" :value="money($closing)" icon="report" tone="info" hint="Position at the end of the period" />
    </div>

    <div class="mt-6">
        <x-card title="Statement summary" description="{{ $from }} to {{ $to }}" :padding="false">
            <div class="table-wrap !border-0 !rounded-none">
                <table class="table-base">
                    <tbody>
                        <tr>
                            <td class="text-ink-soft">Opening cash & bank</td>
                            <td class="text-right font-medium text-ink">{{ money($opening) }}</td>
                        </tr>
                        <tr>
                            <td class="text-ink-soft">Cash inflows</td>
                            <td class="text-right font-medium text-emerald-600 dark:text-emerald-400">+{{ money($inflows) }}</td>
                        </tr>
                        <tr>
                            <td class="text-ink-soft">Cash outflows</td>
                            <td class="text-right font-medium text-rose-600 dark:text-rose-400">−{{ money($outflows) }}</td>
                        </tr>
                        <tr>
                            <td class="font-semibold text-ink">Net change</td>
                            <td class="text-right font-semibold {{ $net >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' }}">
                                {{ $net >= 0 ? '+' : '−' }}{{ money(abs($net)) }}
                            </td>
                        </tr>
                        <tr>
                            <td class="font-semibold text-ink">Closing cash & bank</td>
                            <td class="text-right font-bold text-primary">{{ money($closing) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </x-card>
    </div>
</x-app-layout>
