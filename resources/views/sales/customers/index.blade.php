<x-app-layout :pageTitle="'Customers'">
    <x-slot name="header">
        <x-page-header
            title="Customers"
            description="Your customer base — quotes, orders, invoices and statements."
            icon="users"
        >
            <x-slot name="actions">
                @if (auth()->user()->can('sales.customers.export'))
                    <x-export route="sales.customers.export" />
                @endif
                @if (auth()->user()->can('sales.customers.create'))
                    <x-button href="{{ route('sales.customers.create') }}" icon="plus">New customer</x-button>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    <x-card :padding="false">
        <form method="GET" action="{{ route('sales.customers.index') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
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
                    <x-button href="{{ route('sales.customers.index') }}" variant="ghost" size="sm">Clear</x-button>
                @endif
            </div>
        </form>

        @if ($customers->isEmpty())
            <x-empty-state icon="users" title="No customers found" description="Create a customer to start selling." />
        @else
            <div class="table-wrap !border-0 !rounded-none">
                <table class="table-base">
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Contact</th>
                            <th>Location</th>
                            <th class="text-right">Balance</th>
                            <th>Status</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($customers as $customer)
                            <tr>
                                <td>
                                    <a href="{{ route('sales.customers.show', $customer) }}" class="flex items-center gap-3">
                                        <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                            <x-icon name="company" class="size-4" />
                                        </div>
                                        <div class="min-w-0">
                                            <p class="font-medium text-ink">{{ $customer->company_name }}</p>
                                            <p class="text-xs text-ink-faint">{{ $customer->email ?: 'No email' }}</p>
                                        </div>
                                    </a>
                                </td>
                                <td class="text-ink-soft">{{ $customer->contact_name ?: '—' }}</td>
                                <td class="text-ink-soft">{{ $customer->city ?: ($customer->country ?: '—') }}</td>
                                <td class="text-right">
                                    @if ($customer->balance() > 0)
                                        <span class="font-medium text-rose-600 dark:text-rose-400">{{ money($customer->balance(), $customer->currency) }}</span>
                                    @else
                                        <span class="font-medium text-emerald-600 dark:text-emerald-400">{{ money($customer->balance(), $customer->currency) }}</span>
                                    @endif
                                </td>
                                <td>
                                    <x-sales.status-badge :status="$customer->is_active ? 'active' : 'inactive'" />
                                </td>
                                <td class="text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        <a href="{{ route('sales.customers.show', $customer) }}" class="btn-ghost btn-icon btn-sm" title="View">
                                            <x-icon name="eye" class="size-4" />
                                        </a>
                                        @if (auth()->user()->can('sales.customers.edit'))
                                            <a href="{{ route('sales.customers.edit', $customer) }}" class="btn-ghost btn-icon btn-sm" title="Edit">
                                                <x-icon name="edit" class="size-4" />
                                            </a>
                                        @endif
                                        @if (auth()->user()->can('sales.customers.delete'))
                                            <form method="POST" action="{{ route('sales.customers.destroy', $customer) }}"
                                                onsubmit="return confirm('Delete customer {{ $customer->company_name }}? This also removes all their documents.');">
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

        @if ($customers->hasPages())
            <div class="px-5 py-4">
                {{ $customers->links() }}
            </div>
        @endif
    </x-card>
</x-app-layout>
