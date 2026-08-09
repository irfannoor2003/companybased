<x-app-layout :pageTitle="'Delivery notes'">
    <x-slot name="header">
        <x-page-header
            title="Delivery notes"
            description="Packing, shipping and delivering orders to customers."
            icon="truck"
        >
            <x-slot name="actions">
                @if (auth()->user()->can('sales.delivery_notes.export'))
                    <x-export route="sales.delivery_notes.export" />
                @endif
                @if (auth()->user()->can('sales.delivery_notes.create'))
                    <x-button href="{{ route('sales.delivery_notes.create') }}" icon="plus">New delivery note</x-button>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    <x-card :padding="false">
        <form method="GET" action="{{ route('sales.delivery_notes.index') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
            <div class="min-w-[220px] flex-1">
                <x-input name="search" label="Search" placeholder="Delivery note number…" leadingIcon="search"
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
            <div class="w-44">
                <x-select name="status" label="Status" size="sm">
                    <option value="">Any status</option>
                    @foreach (\App\Models\SalesDeliveryNote::statusOptions() as $status)
                        <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </x-select>
            </div>
            <div class="flex gap-2">
                <x-button type="submit" size="sm" icon="filter">Filter</x-button>
                @if (request()->hasAny(['search', 'customer', 'status']))
                    <x-button href="{{ route('sales.delivery_notes.index') }}" variant="ghost" size="sm">Clear</x-button>
                @endif
            </div>
        </form>

        @if ($deliveryNotes->isEmpty())
            <x-empty-state icon="truck" title="No delivery notes found" description="Create a delivery note to track fulfilment." />
        @else
            <div class="table-wrap !border-0 !rounded-none">
                <table class="table-base">
                    <thead>
                        <tr>
                            <th>Delivery note</th>
                            <th>Customer</th>
                            <th>Order</th>
                            <th>Issued</th>
                            <th>Status</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($deliveryNotes as $note)
                            <tr>
                                <td>
                                    <a href="{{ route('sales.delivery_notes.edit', $note) }}" class="font-medium text-ink hover:text-primary">{{ $note->number }}</a>
                                </td>
                                <td class="text-ink-soft">{{ $note->customer?->company_name }}</td>
                                <td class="text-ink-soft">{{ $note->order?->number ?: '—' }}</td>
                                <td class="text-ink-soft">{{ $note->issue_date?->format('Y-m-d') }}</td>
                                <td><x-sales.status-badge :status="$note->status" /></td>
                                <td class="text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        <a href="{{ route('sales.delivery_notes.edit', $note) }}" class="btn-ghost btn-icon btn-sm" title="Edit">
                                            <x-icon name="edit" class="size-4" />
                                        </a>
                                        @if (auth()->user()->can('sales.delivery_notes.delete'))
                                            <form method="POST" action="{{ route('sales.delivery_notes.destroy', $note) }}"
                                                onsubmit="return confirm('Delete delivery note {{ $note->number }}?');">
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

        @if ($deliveryNotes->hasPages())
            <div class="px-5 py-4">
                {{ $deliveryNotes->links() }}
            </div>
        @endif
    </x-card>
</x-app-layout>
