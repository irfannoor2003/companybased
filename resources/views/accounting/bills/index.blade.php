<x-app-layout :pageTitle="'Bills'">
    <x-slot name="header">
        <x-page-header title="Bills" description="Accounts payable — vendor bills mapped to expense accounts." icon="invoice">
            <x-slot name="actions">
                @if (auth()->user()->can('accounting.bills.export'))
                    <x-export route="accounting.bills.export" />
                @endif
                @if (auth()->user()->can('accounting.bills.create'))
                    <x-button href="{{ route('accounting.bills.create') }}" icon="plus">New bill</x-button>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    @include('accounting._tabs')

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-stat-card label="Outstanding" :value="money($totalDue)" icon="clock" tone="warning" hint="Total unpaid balance" />
        <x-stat-card label="Paid to date" :value="money($totalPaid)" icon="check" tone="success" hint="Amount paid across all bills" />
        <x-stat-card label="Bills shown" :value="$bills->total()" icon="document" tone="neutral" />
    </div>

    <div class="mt-6">
        <x-card :padding="false">
            <form method="GET" action="{{ route('accounting.bills.index') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
                <div class="min-w-[220px] flex-1">
                    <x-input name="search" label="Search" placeholder="Number, vendor, reference…" leadingIcon="search"
                        value="{{ request('search') }}" size="sm" />
                </div>
                <div class="w-36">
                    <x-select name="status" label="Status" size="sm">
                        <option value="">Any status</option>
                        @foreach (\App\Models\Bill::statusOptions() as $status)
                            <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
                        @endforeach
                    </x-select>
                </div>
                <div>
                    <x-input name="from" label="From" type="date" value="{{ request('from') }}" size="sm" />
                </div>
                <div>
                    <x-input name="to" label="To" type="date" value="{{ request('to') }}" size="sm" />
                </div>
                <div class="flex gap-2">
                    <x-button type="submit" size="sm" icon="filter">Filter</x-button>
                    @if (request()->hasAny(['search', 'status', 'from', 'to']))
                        <x-button href="{{ route('accounting.bills.index') }}" variant="ghost" size="sm">Clear</x-button>
                    @endif
                </div>
            </form>

            @if ($bills->isEmpty())
                <x-empty-state icon="invoice" title="No bills" description="Record a vendor bill to track accounts payable." />
            @else
                <div class="table-wrap !border-0 !rounded-none">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th>Number</th>
                                <th>Date</th>
                                <th>Vendor</th>
                                <th>Due date</th>
                                <th>Status</th>
                                <th class="text-right">Amount</th>
                                <th class="text-right">Balance</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($bills as $bill)
                                <tr>
                                    <td>
                                        <a href="{{ route('accounting.bills.show', $bill) }}" class="font-mono text-xs font-medium text-primary hover:underline">
                                            {{ $bill->number }}
                                        </a>
                                    </td>
                                    <td class="text-ink-soft">{{ $bill->bill_date->format('Y-m-d') }}</td>
                                    <td class="text-ink">{{ $bill->vendor_name }}</td>
                                    <td class="text-ink-soft">{{ $bill->due_date?->format('Y-m-d') ?: '—' }}</td>
                                    <td><x-accounting.status-badge :status="$bill->status" /></td>
                                    <td class="text-right text-ink">{{ money($bill->amount, $bill->currency) }}</td>
                                    <td class="text-right font-medium {{ $bill->balance() > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400' }}">{{ money($bill->balance(), $bill->currency) }}</td>
                                    <td class="text-right">
                                        <a href="{{ route('accounting.bills.show', $bill) }}" class="btn-ghost btn-icon btn-sm" title="View">
                                            <x-icon name="eye" class="size-4" />
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($bills->hasPages())
                <div class="px-5 py-4">
                    {{ $bills->links() }}
                </div>
            @endif
        </x-card>
    </div>
</x-app-layout>