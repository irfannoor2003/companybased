<x-app-layout :pageTitle="'Suppliers'">
    <x-slot name="header">
        <x-page-header
            title="Suppliers"
            description="Your vendor base — purchase quotes, orders, invoices and ledger."
            icon="suppliers"
        >
            <x-slot name="actions">
                @if (auth()->user()->can('suppliers.suppliers.export'))
                    <x-export route="suppliers.suppliers.export" />
                @endif
                @if (auth()->user()->can('suppliers.suppliers.create'))
                    <x-button href="{{ route('suppliers.suppliers.create') }}" icon="plus">New supplier</x-button>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    <x-card :padding="false">
        <form method="GET" action="{{ route('suppliers.suppliers.index') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
            <div class="min-w-[220px] flex-1">
                <x-input name="search" label="Search" placeholder="Name, contact, email or tax no…" leadingIcon="search"
                    value="{{ request('search') }}" size="sm" />
            </div>
            <div class="w-36">
                <x-select name="status" label="Status" size="sm">
                    <option value="">Any status</option>
                    <option value="active" @selected(request('status') === 'active')>Active</option>
                    <option value="inactive" @selected(request('status') === 'inactive')>Inactive</option>
                </x-select>
            </div>
            <div class="flex gap-2">
                <x-button type="submit" size="sm" icon="filter">Filter</x-button>
                @if (request()->hasAny(['search', 'status']))
                    <x-button href="{{ route('suppliers.suppliers.index') }}" variant="ghost" size="sm">Clear</x-button>
                @endif
            </div>
        </form>

        @if ($suppliers->isEmpty())
            <x-empty-state icon="suppliers" title="No suppliers found" description="Create a supplier to start purchasing." />
        @else
            <div class="table-wrap !border-0 !rounded-none">
                <table class="table-base">
                <thead>
                    <tr>
                        <th>Supplier</th>
                        <th>Short code</th>
                        <th>Contact</th>
                        <th>Location</th>
                        <th class="text-right">Balance</th>
                        <th>Status</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($suppliers as $supplier)
                        <tr>
                            <td>
                                <a href="{{ route('suppliers.suppliers.show', $supplier) }}" class="flex items-center gap-3">
                                    <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                        <x-icon name="company" class="size-4" />
                                    </div>
                                    <div class="min-w-0">
                                        <p class="font-medium text-ink">{{ $supplier->company_name }}</p>
                                        <p class="text-xs text-ink-faint">{{ $supplier->email ?: 'No email' }}</p>
                                    </div>
                                </a>
                            </td>
                            <td class="text-ink-soft font-mono">{{ $supplier->short_code ?: '—' }}</td>
                            <td class="text-ink-soft">{{ $supplier->contact_name ?: '—' }}</td>
                                <td class="text-ink-soft">{{ $supplier->city ?: ($supplier->country ?: '—') }}</td>
                                <td class="text-right">
                                    @php
                                        $billed = (float) $supplier->purchaseInvoices->sum('total');
                                        $paid = (float) $supplier->payments->sum('amount');
                                        $credited = (float) $supplier->debitNotes->sum('applied_amount');
                                        $balance = round($billed - $paid - $credited, 2);
                                    @endphp
                                    @if ($balance > 0)
                                        <span class="font-medium text-rose-600 dark:text-rose-400">{{ money($balance, $supplier->currency) }}</span>
                                    @else
                                        <span class="font-medium text-emerald-600 dark:text-emerald-400">{{ money($balance, $supplier->currency) }}</span>
                                    @endif
                                </td>
                                <td>
                                    <x-suppliers.status-badge :status="$supplier->is_active ? 'active' : 'inactive'" />
                                </td>
                                <td class="text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        <a href="{{ route('suppliers.suppliers.show', $supplier) }}" class="btn-ghost btn-icon btn-sm" title="View">
                                            <x-icon name="eye" class="size-4" />
                                        </a>
                                        @if (auth()->user()->can('suppliers.suppliers.edit'))
                                            <a href="{{ route('suppliers.suppliers.edit', $supplier) }}" class="btn-ghost btn-icon btn-sm" title="Edit">
                                                <x-icon name="edit" class="size-4" />
                                            </a>
                                        @endif
                                        @if (auth()->user()->can('suppliers.suppliers.delete'))
                                            <form method="POST" action="{{ route('suppliers.suppliers.destroy', $supplier) }}"
                                                onsubmit="return confirm('Delete supplier {{ $supplier->company_name }}? This also removes all their documents.');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn-ghost btn-icon btn-sm text-rose-500" title="Delete">
                                                    <x-icon name="trash" class="size-4" />
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($suppliers->hasPages())
            <div class="px-5 py-4">
                {{ $suppliers->links() }}
            </div>
        @endif
    </x-card>
</x-app-layout>
