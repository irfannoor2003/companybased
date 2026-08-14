<x-app-layout :pageTitle="'Statements'">
    <x-slot name="header">
        <x-page-header
            title="Statements"
            description="Customer account statements — invoices, payments, credit notes and running balances."
            icon="report"
        />
    </x-slot>

    <div class="mt-6" x-data="{ loaded: false }" x-init="loaded = true">
        {{-- Skeleton: stat cards --}}
        <div x-show="!loaded" class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach (range(1, 4) as $i)
                <div class="rounded-xl border border-line bg-surface p-5">
                    <div class="h-3 w-24 animate-pulse rounded bg-surface-muted"></div>
                    <div class="mt-3 h-7 w-20 animate-pulse rounded bg-surface-muted"></div>
                </div>
            @endforeach
        </div>
        {{-- Skeleton: table --}}
        <div x-show="!loaded" class="mt-6 rounded-xl border border-line bg-surface">
            <div class="p-5 space-y-3">
                @foreach (range(1, 5) as $i)
                    <div class="flex items-center gap-4">
                        <div class="h-3 w-36 animate-pulse rounded bg-surface-muted"></div>
                        <div class="ml-auto h-3 w-20 animate-pulse rounded bg-surface-muted"></div>
                        <div class="h-3 w-20 animate-pulse rounded bg-surface-muted"></div>
                        <div class="h-3 w-20 animate-pulse rounded bg-surface-muted"></div>
                        <div class="h-3 w-20 animate-pulse rounded bg-surface-muted"></div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Real content --}}
        <div x-show="loaded" x-cloak>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <x-card title="Total outstanding">
                    <p class="text-xl font-semibold text-ink">{{ money($grandTotal) }}</p>
                </x-card>
                <x-card title="Customers on file">
                    <p class="text-xl font-semibold text-ink">{{ $customers->count() }}</p>
                </x-card>
                <x-card title="With balance">
                    <p class="text-xl font-semibold text-ink">{{ $customers->where('balance', '>', 0)->count() }}</p>
                </x-card>
                <x-card title="With overdue invoices">
                    <p class="text-xl font-semibold text-ink">{{ $customers->where('overdue', '>', 0)->count() }}</p>
                </x-card>
            </div>

    <x-card :padding="false" class="mt-6">
        <form method="GET" action="{{ route('sales.statements.index') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
            <div class="min-w-[220px] flex-1">
                <x-input name="search" label="Search" placeholder="Company, contact, email…" leadingIcon="search"
                    value="{{ request('search') }}" size="sm" />
            </div>
            <div class="flex gap-2">
                <x-button type="submit" size="sm" icon="filter">Filter</x-button>
                @if (request()->filled('search'))
                    <x-button href="{{ route('sales.statements.index') }}" variant="ghost" size="sm">Clear</x-button>
                @endif
            </div>
        </form>

        @if ($customers->isEmpty())
            <x-empty-state icon="users" title="No customers found" />
        @else
            <div class="table-wrap !border-0 !rounded-none">
                <table class="table-base">
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th class="text-right">Billed</th>
                            <th class="text-right">Paid</th>
                            <th class="text-right">Overdue</th>
                            <th class="text-right">Balance</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($customers as $row)
                            @php($c = $row['customer'])
                            <tr>
                                <td>
                                    <p class="font-medium text-ink">{{ $c->company_name }}</p>
                                    <p class="text-xs text-ink-faint">{{ $c->email ?: $c->contact_name }}</p>
                                </td>
                                <td class="text-right text-ink-soft">{{ money($row['billed'], $c->currency) }}</td>
                                <td class="text-right text-ink-soft">{{ money($row['paid'], $c->currency) }}</td>
                                <td class="text-right">
                                    @if ($row['overdue'] > 0)
                                        <span class="font-medium text-rose-600 dark:text-rose-400">{{ money($row['overdue'], $c->currency) }}</span>
                                    @else
                                        <span class="text-ink-faint">—</span>
                                    @endif
                                </td>
                                <td class="text-right">
                                    @if ($row['balance'] > 0)
                                        <span class="font-medium text-rose-600 dark:text-rose-400">{{ money($row['balance'], $c->currency) }}</span>
                                    @else
                                        <span class="font-medium text-emerald-600 dark:text-emerald-400">{{ money($row['balance'], $c->currency) }}</span>
                                    @endif
                                </td>
                                <td class="text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        <a href="{{ route('sales.statements.show', $c) }}" class="btn-ghost btn-icon btn-sm" title="View statement">
                                            <x-icon name="eye" class="size-4" />
                                        </a>
                                        @if (auth()->user()->can('sales.statements.export'))
                                            <a href="{{ route('sales.statements.export', $c) }}" class="btn-ghost btn-icon btn-sm" title="Export CSV">
                                                <x-icon name="export" class="size-4" />
                                            </a>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-card>
        </div> {{-- end real content --}}
    </div>
</x-app-layout>
