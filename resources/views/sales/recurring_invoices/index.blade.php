<x-app-layout :pageTitle="'Recurring invoices'">
    <x-slot name="header">
        <x-page-header
            title="Recurring invoices"
            description="Automatically bill customers on a repeating schedule."
            icon="repeat"
        >
            <x-slot name="actions">
                @if (auth()->user()->can('sales.recurring_invoices.export'))
                    <x-export route="sales.recurring_invoices.export" />
                @endif
                @if (auth()->user()->can('sales.recurring_invoices.create'))
                    <x-button href="{{ route('sales.recurring_invoices.create') }}" icon="plus">New recurring invoice</x-button>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    <x-card :padding="false">
        <form method="GET" action="{{ route('sales.recurring_invoices.index') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
            <div class="min-w-[220px] flex-1">
                <x-input name="search" label="Search" placeholder="Template name…" leadingIcon="search"
                    value="{{ request('search') }}" size="sm" />
            </div>
            <div class="w-56">
                <x-select name="customer" label="Customer" size="sm">
                    <option value="">All customers</option>
                    @foreach ($customers as $customer)
                        <option value="{{ $customer->id }}" @selected(request('customer') == $customer->id)>{{ $customer->company_name }}</option>
                    @endforeach
                </x-select>
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
                @if (request()->hasAny(['search', 'customer', 'status']))
                    <x-button href="{{ route('sales.recurring_invoices.index') }}" variant="ghost" size="sm">Clear</x-button>
                @endif
            </div>
        </form>

        @if ($recurringInvoices->isEmpty())
            <x-empty-state icon="repeat" title="No recurring invoices found" description="Set up a recurring template to automate billing." />
        @else
            <div class="table-wrap !border-0 !rounded-none">
                <table class="table-base">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Customer</th>
                            <th>Frequency</th>
                            <th>Next run</th>
                            <th class="text-right">Amount</th>
                            <th>Status</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($recurringInvoices as $recurring)
                            <tr>
                                <td>
                                    <a href="{{ route('sales.recurring_invoices.edit', $recurring) }}" class="font-medium text-ink hover:text-primary">{{ $recurring->name }}</a>
                                </td>
                                <td class="text-ink-soft">{{ $recurring->customer?->company_name }}</td>
                                <td class="text-ink-soft">{{ ucfirst($recurring->frequency) }}</td>
                                <td class="text-ink-soft">{{ $recurring->next_run_date?->format('Y-m-d') }}</td>
                                <td class="text-right font-medium text-ink">{{ money($recurring->total, $recurring->currency) }}</td>
                                <td><x-sales.status-badge :status="$recurring->is_active ? 'active' : 'inactive'" /></td>
                                <td class="text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        @if (auth()->user()->can('sales.recurring_invoices.view'))
                                            <a href="{{ route('sales.recurring_invoices.show', $recurring) }}" class="btn-ghost btn-icon btn-sm" title="View">
                                                <x-icon name="eye" class="size-4" />
                                            </a>
                                        @endif
                                        <a href="{{ route('sales.recurring_invoices.edit', $recurring) }}" class="btn-ghost btn-icon btn-sm" title="Edit">
                                            <x-icon name="edit" class="size-4" />
                                        </a>
                                        @if (auth()->user()->can('sales.recurring_invoices.delete'))
                                            <form method="POST" action="{{ route('sales.recurring_invoices.destroy', $recurring) }}"
                                                onsubmit="return confirm('Delete recurring invoice "{{ $recurring->name }}"?');">
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

        @if ($recurringInvoices->hasPages())
            <div class="px-5 py-4">
                {{ $recurringInvoices->links() }}
            </div>
        @endif
    </x-card>
</x-app-layout>
