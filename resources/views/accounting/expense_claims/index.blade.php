<x-app-layout :pageTitle="'Expense claims'">
    <x-slot name="header">
        <x-page-header title="Expense claims" description="Employee reimbursement requests with an approval workflow." icon="money">
            <x-slot name="actions">
                @if (auth()->user()->can('accounting.expense_claims.export'))
                    <x-export route="accounting.expense_claims.export" />
                @endif
                @if (auth()->user()->can('accounting.expense_claims.create'))
                    <x-button href="{{ route('accounting.expense_claims.create') }}" icon="plus">New claim</x-button>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    @include('accounting._tabs')

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-stat-card label="Pending" :value="money($pendingTotal)" icon="clock" tone="warning" hint="Awaiting approval" />
        <x-stat-card label="Approved (unreimbursed)" :value="money($approvedTotal)" icon="check" tone="info" hint="Approved, not yet paid" />
        <x-stat-card label="Claims shown" :value="$claims->total()" icon="document" tone="neutral" />
    </div>

    <div class="mt-6">
        <x-card :padding="false">
            <form method="GET" action="{{ route('accounting.expense_claims.index') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
                <div class="min-w-[220px] flex-1">
                    <x-input name="search" label="Search" placeholder="Number, employee, merchant…" leadingIcon="search"
                        value="{{ request('search') }}" size="sm" />
                </div>
                <div class="w-40">
                    <x-select name="type" label="Type" size="sm">
                        <option value="">Any type</option>
                        @foreach (\App\Models\ExpenseClaim::typeOptions() as $type)
                            <option value="{{ $type }}" @selected(request('type') === $type)>{{ ucfirst($type) }}</option>
                        @endforeach
                    </x-select>
                </div>
                <div class="w-36">
                    <x-select name="status" label="Status" size="sm">
                        <option value="">Any status</option>
                        @foreach (\App\Models\ExpenseClaim::statusOptions() as $status)
                            <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
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
                    @if (request()->hasAny(['search', 'type', 'status', 'from', 'to']))
                        <x-button href="{{ route('accounting.expense_claims.index') }}" variant="ghost" size="sm">Clear</x-button>
                    @endif
                </div>
            </form>

            @if ($claims->isEmpty())
                <x-empty-state icon="money" title="No expense claims" description="Record employee claims to track reimbursements." />
            @else
                <div class="table-wrap !border-0 !rounded-none">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th>Number</th>
                                <th>Date</th>
                                <th>Employee</th>
                                <th>Type</th>
                                <th>Merchant</th>
                                <th>Status</th>
                                <th class="text-right">Amount</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($claims as $claim)
                                <tr>
                                    <td>
                                        <a href="{{ route('accounting.expense_claims.show', $claim) }}" class="font-mono text-xs font-medium text-primary hover:underline">
                                            {{ $claim->number }}
                                        </a>
                                    </td>
                                    <td class="text-ink-soft">{{ $claim->expense_date->format('Y-m-d') }}</td>
                                    <td class="text-ink">{{ $claim->employee_name }}</td>
                                    <td><span class="text-ink-soft">{{ ucfirst($claim->expense_type) }}</span></td>
                                    <td class="text-ink-soft">{{ $claim->merchant ?: '—' }}</td>
                                    <td><x-accounting.status-badge :status="$claim->status" /></td>
                                    <td class="text-right font-medium text-ink">{{ money($claim->amount, $claim->currency) }}</td>
                                    <td class="text-right">
                                        <a href="{{ route('accounting.expense_claims.show', $claim) }}" class="btn-ghost btn-icon btn-sm" title="View">
                                            <x-icon name="eye" class="size-4" />
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($claims->hasPages())
                <div class="px-5 py-4">
                    {{ $claims->links() }}
                </div>
            @endif
        </x-card>
    </div>
</x-app-layout>