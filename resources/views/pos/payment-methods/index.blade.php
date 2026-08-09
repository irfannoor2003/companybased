<x-app-layout :pageTitle="'POS Payment Methods'">
    <x-slot name="header">
        <x-page-header title="Payment Methods" description="Cash, card and mobile money options accepted at the till." icon="money">
            <x-slot name="actions">
                @if (auth()->user()->can('pos.payment_methods.export'))
                    <x-export route="pos.payment_methods.export" />
                @endif
                @if (auth()->user()->can('pos.payment_methods.create'))
                    <x-button href="{{ route('pos.payment_methods.create') }}" icon="plus">Add method</x-button>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    @include('pos._tabs')

    <div class="mt-6">
        <x-card :padding="false">
            <form method="GET" action="{{ route('pos.payment_methods.index') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
                <div class="min-w-[220px] flex-1">
                    <x-input name="search" label="Search" placeholder="Name or code…" leadingIcon="search" value="{{ request('search') }}" size="sm" />
                </div>
                <div class="flex gap-2">
                    <x-button type="submit" size="sm" icon="filter">Filter</x-button>
                    @if (request()->filled('search'))
                        <x-button href="{{ route('pos.payment_methods.index') }}" variant="ghost" size="sm">Clear</x-button>
                    @endif
                </div>
            </form>

            @if ($methods->isEmpty())
                <x-empty-state icon="money" title="No payment methods" description="Add a payment method to use at the till." />
            @else
                <div class="table-wrap !border-0 !rounded-none">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Name</th>
                                <th>Cash</th>
                                <th>Active</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($methods as $method)
                                <tr>
                                    <td class="font-mono font-medium text-ink">{{ $method->code }}</td>
                                    <td class="text-ink-soft">{{ $method->name }}</td>
                                    <td>
                                        <x-badge :color="$method->is_cash ? 'success' : 'neutral'">{{ $method->is_cash ? 'Cash' : '—' }}</x-badge>
                                    </td>
                                    <td>
                                        <x-badge :color="$method->is_active ? 'success' : 'danger'">{{ $method->is_active ? 'Active' : 'Inactive' }}</x-badge>
                                    </td>
                                    <td class="text-right">
                                        @if (auth()->user()->can('pos.payment_methods.edit'))
                                            <a href="{{ route('pos.payment_methods.edit', $method) }}" class="btn-ghost btn-icon btn-sm" title="Edit">
                                                <x-icon name="edit" class="size-4" />
                                            </a>
                                        @endif
                                        @if (auth()->user()->can('pos.payment_methods.delete'))
                                            <form method="POST" action="{{ route('pos.payment_methods.destroy', $method) }}" class="inline" onsubmit="return confirm('Delete {{ $method->name }}?')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn-ghost btn-icon btn-sm text-danger" title="Delete">
                                                    <x-icon name="trash" class="size-4" />
                                                </button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($methods->hasPages())
                <div class="px-5 py-4">
                    {{ $methods->links() }}
                </div>
            @endif
        </x-card>
    </div>
</x-app-layout>