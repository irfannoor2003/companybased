<x-app-layout :pageTitle="'Purchase orders'">
    <x-slot name="header">
        <x-page-header
            title="Purchase orders"
            description="Confirmed supplier orders through sending, receiving and completion."
            icon="orders"
        >
            <x-slot name="actions">
                @if (auth()->user()->can('suppliers.purchase_orders.export'))
                    <x-export route="suppliers.purchase_orders.export" />
                @endif
                @if (auth()->user()->can('suppliers.purchase_orders.create'))
                    <x-button href="{{ route('suppliers.purchase_orders.create') }}" icon="plus">New purchase order</x-button>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    <x-card :padding="false">
        <form method="GET" action="{{ route('suppliers.purchase_orders.index') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
            <div class="min-w-[220px] flex-1">
                <x-input name="search" label="Search" placeholder="Order number…" leadingIcon="search"
                    value="{{ request('search') }}" size="sm" />
            </div>
            <div class="w-56">
                <x-select name="supplier" label="Supplier" size="sm">
                    <option value="">All suppliers</option>
                    @foreach ($suppliers as $supplier)
                        <option value="{{ $supplier->id }}" @selected(request('supplier') == $supplier->id)>{{ $supplier->company_name }}</option>
                    @endforeach
                </x-select>
            </div>
            <div class="w-44">
                <x-select name="status" label="Status" size="sm">
                    <option value="">Any status</option>
                    @foreach (\App\Models\PurchaseOrder::statusOptions() as $status)
                        <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
                    @endforeach
                </x-select>
            </div>
            <div class="flex gap-2">
                <x-button type="submit" size="sm" icon="filter">Filter</x-button>
                @if (request()->hasAny(['search', 'supplier', 'status']))
                    <x-button href="{{ route('suppliers.purchase_orders.index') }}" variant="ghost" size="sm">Clear</x-button>
                @endif
            </div>
        </form>

        @if ($orders->isEmpty())
            <x-empty-state icon="orders" title="No purchase orders found" description="Create or convert a purchase quote into an order." />
        @else
            <div class="table-wrap !border-0 !rounded-none">
                <table class="table-base">
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Supplier</th>
                            <th>Ordered</th>
                            <th>Expected delivery</th>
                            <th>Status</th>
                            <th class="text-right">Total</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($orders as $order)
                            <tr>
                                <td>
                                    <a href="{{ route('suppliers.purchase_orders.edit', $order) }}" class="font-medium text-ink hover:text-primary">{{ $order->number }}</a>
                                </td>
                                <td class="text-ink-soft">{{ $order->supplier?->company_name }}</td>
                                <td class="text-ink-soft">{{ $order->order_date?->format('Y-m-d') }}</td>
                                <td class="text-ink-soft">{{ $order->expected_delivery_date?->format('Y-m-d') ?: '—' }}</td>
                                <td><x-suppliers.status-badge :status="$order->status" /></td>
                                <td class="text-right font-medium text-ink">{{ money($order->total, $order->currency) }}</td>
                                <td class="text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        @if (auth()->user()->can('suppliers.purchase_orders.confirm') && $order->status === 'draft')
                                            <form method="POST" action="{{ route('suppliers.purchase_orders.confirm', $order) }}">
                                                @csrf
                                                <button type="submit" class="btn-ghost btn-icon btn-sm" title="Confirm order">
                                                    <x-icon name="check-circle" class="size-4" />
                                                </button>
                                            </form>
                                        @endif
                                        @if (auth()->user()->can('suppliers.purchase_orders.view'))
                                            <a href="{{ route('suppliers.purchase_orders.show', $order) }}" class="btn-ghost btn-icon btn-sm" title="View">
                                                <x-icon name="eye" class="size-4" />
                                            </a>
                                        @endif
                                        <a href="{{ route('suppliers.purchase_orders.edit', $order) }}" class="btn-ghost btn-icon btn-sm" title="Edit">
                                            <x-icon name="edit" class="size-4" />
                                        </a>
                                        @if (auth()->user()->can('suppliers.purchase_orders.delete'))
                                            <form method="POST" action="{{ route('suppliers.purchase_orders.destroy', $order) }}"
                                                onsubmit="return confirm('Delete purchase order {{ $order->number }}?');">
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

        @if ($orders->hasPages())
            <div class="px-5 py-4">
                {{ $orders->links() }}
            </div>
        @endif
    </x-card>
</x-app-layout>
