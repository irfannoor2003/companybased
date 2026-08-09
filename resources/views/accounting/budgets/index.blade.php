<x-app-layout :pageTitle="'Budgets'">
    <x-slot name="header">
        <x-page-header title="Budgets" description="Plan revenue and expense targets per account and track actuals." icon="chart">
            <x-slot name="actions">
                @if (auth()->user()->can('accounting.budgeting.export'))
                    <x-export route="accounting.budgets.export" />
                @endif
                @if (auth()->user()->can('accounting.budgeting.create'))
                    <x-button href="{{ route('accounting.budgets.create') }}" icon="plus">New budget</x-button>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    @include('accounting._tabs')

    <div class="mt-6">
        <x-card :padding="false">
            <form method="GET" action="{{ route('accounting.budgets.index') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
                <div class="min-w-[220px] flex-1">
                    <x-input name="search" label="Search" placeholder="Budget name, fiscal year…" leadingIcon="search"
                        value="{{ request('search') }}" size="sm" />
                </div>
                <div class="w-36">
                    <x-select name="status" label="Status" size="sm">
                        <option value="">Any status</option>
                        @foreach (\App\Models\Budget::statusOptions() as $status)
                            <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
                        @endforeach
                    </x-select>
                </div>
                <div class="w-28">
                    <x-input name="year" label="Fiscal year" placeholder="2026" value="{{ request('year') }}" size="sm" />
                </div>
                <div class="flex gap-2">
                    <x-button type="submit" size="sm" icon="filter">Filter</x-button>
                    @if (request()->hasAny(['search', 'status', 'year']))
                        <x-button href="{{ route('accounting.budgets.index') }}" variant="ghost" size="sm">Clear</x-button>
                    @endif
                </div>
            </form>

            @if ($budgets->isEmpty())
                <x-empty-state icon="chart" title="No budgets" description="Create a budget for a fiscal year to plan account targets." />
            @else
                <div class="table-wrap !border-0 !rounded-none">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Fiscal year</th>
                                <th>Status</th>
                                <th class="text-right">Items</th>
                                <th class="text-right">Budgeted</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($budgets as $budget)
                                <tr>
                                    <td>
                                        <a href="{{ route('accounting.budgets.show', $budget) }}" class="font-medium text-ink hover:text-primary">
                                            {{ $budget->name }}
                                        </a>
                                    </td>
                                    <td class="text-ink-soft">{{ $budget->fiscal_year }}</td>
                                    <td><x-accounting.status-badge :status="$budget->status" /></td>
                                    <td class="text-right text-ink-soft">{{ $budget->items_count }}</td>
                                    <td class="text-right font-medium text-ink">{{ money($budget->totalBudgeted(), $budget->currency) }}</td>
                                    <td class="text-right">
                                        <a href="{{ route('accounting.budgets.show', $budget) }}" class="btn-ghost btn-icon btn-sm" title="View">
                                            <x-icon name="eye" class="size-4" />
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($budgets->hasPages())
                <div class="px-5 py-4">
                    {{ $budgets->links() }}
                </div>
            @endif
        </x-card>
    </div>
</x-app-layout>