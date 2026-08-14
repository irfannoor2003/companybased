<x-app-layout :pageTitle="'Supplier ledger'">
    <x-slot name="header">
        <x-page-header
            title="Supplier ledger"
            description="Supplier account balances — invoices, payments and debit notes."
            icon="report"
        />
    </x-slot>

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-card title="Total payable">
            <p class="text-xl font-semibold text-ink">{{ money($grandTotal) }}</p>
        </x-card>
        <x-card title="Suppliers on file">
            <p class="text-xl font-semibold text-ink">{{ $suppliers->count() }}</p>
        </x-card>
        <x-card title="With balance">
            <p class="text-xl font-semibold text-ink">{{ $suppliers->where('balance', '>', 0)->count() }}</p>
        </x-card>
        <x-card title="With overdue invoices">
            <p class="text-xl font-semibold text-ink">{{ $suppliers->where('overdue', '>', 0)->count() }}</p>
        </x-card>
    </div>

    <x-card :padding="false" class="mt-6">
        <form method="GET" action="{{ route('suppliers.supplier_ledger.index') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
            <div class="min-w-[220px] flex-1">
                <x-input name="search" label="Search" placeholder="Company, contact, email…" leadingIcon="search"
                    value="{{ request('search') }}" size="sm" />
            </div>
            <div class="flex gap-2">
                <x-button type="submit" size="sm" icon="filter">Filter</x-button>
                @if (request()->filled('search'))
                    <x-button href="{{ route('suppliers.supplier_ledger.index') }}" variant="ghost" size="sm">Clear</x-button>
                @endif
            </div>
        </form>

        @if ($suppliers->isEmpty())
            <x-empty-state icon="suppliers" title="No suppliers found" />
        @else
            <div class="table-wrap !border-0 !rounded-none">
                <table class="table-base">
                    <thead>
                        <tr>
                            <th>Supplier</th>
                            <th class="text-right">Billed</th>
                            <th class="text-right">Paid</th>
                            <th class="text-right">Overdue</th>
                            <th class="text-right">Balance</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($suppliers as $row)
                            @php($s = $row['supplier'])
                            <tr>
                                <td>
                                    <p class="font-medium text-ink">{{ $s->company_name }}</p>
                                    <p class="text-xs text-ink-faint">{{ $s->email ?: $s->contact_name }}</p>
                                </td>
                                <td class="text-right text-ink-soft">{{ money($row['billed'], $s->currency) }}</td>
                                <td class="text-right text-ink-soft">{{ money($row['paid'], $s->currency) }}</td>
                                <td class="text-right">
                                    @if ($row['overdue'] > 0)
                                        <span class="font-medium text-rose-600 dark:text-rose-400">{{ money($row['overdue'], $s->currency) }}</span>
                                    @else
                                        <span class="text-ink-faint">—</span>
                                    @endif
                                </td>
                                <td class="text-right">
                                    @if ($row['balance'] > 0)
                                        <span class="font-medium text-rose-600 dark:text-rose-400">{{ money($row['balance'], $s->currency) }}</span>
                                    @else
                                        <span class="font-medium text-emerald-600 dark:text-emerald-400">{{ money($row['balance'], $s->currency) }}</span>
                                    @endif
                                </td>
                                <td class="text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        <a href="{{ route('suppliers.supplier_ledger.show', $s) }}" class="btn-ghost btn-icon btn-sm" title="View ledger">
                                            <x-icon name="eye" class="size-4" />
                                        </a>
                                        @if (auth()->user()->can('suppliers.supplier_ledger.export'))
                                            <a href="{{ route('suppliers.supplier_ledger.export', $s) }}" class="btn-ghost btn-icon btn-sm" title="Export CSV">
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
</x-app-layout>
