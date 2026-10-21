<x-app-layout :pageTitle="'Expense categories'">
    <x-slot name="header">
        <x-page-header title="Expense categories" description="Group purchase-bill lines into expense categories wired to the chart of accounts." icon="database" />
    </x-slot>

    @include('accounting._tabs')

    <div class="mt-6 space-y-4">
        <div class="flex items-center justify-between gap-3">
            <form method="GET" class="flex items-center gap-2">
                <x-input name="search" label="Search" placeholder="Category name…" value="{{ request('search') }}" />
                <x-button type="submit" variant="secondary" icon="search">Search</x-button>
            </form>
            <x-button href="{{ route('accounting.expense_categories.create') }}" icon="plus">New category</x-button>
        </div>

        <x-card>
            @if ($categories->isEmpty())
                <x-empty-state icon="document" title="No expense categories" description="Create a category to organise your bill lines." />
            @else
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-ink-soft">
                            <th class="py-2">Name</th>
                            <th class="py-2">Account</th>
                            <th class="py-2">Status</th>
                            <th class="py-2 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($categories as $category)
                            <tr class="border-t border-line">
                                <td class="py-2 font-medium text-ink">{{ $category->name }}</td>
                                <td class="py-2 text-ink-soft">{{ $category->account ? $category->account->code.' — '.$category->account->name : '—' }}</td>
                                <td class="py-2">
                                    @if ($category->is_active)
                                        <span class="text-emerald-600">Active</span>
                                    @else
                                        <span class="text-ink-faint">Inactive</span>
                                    @endif
                                </td>
                                <td class="py-2 text-right">
                                    <a href="{{ route('accounting.expense_categories.edit', $category) }}" class="btn-ghost btn-sm">Edit</a>
                                    <form method="POST" action="{{ route('accounting.expense_categories.destroy', $category) }}" class="inline" onsubmit="return confirm('Delete {{ $category->name }}?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn-ghost btn-sm text-rose-500">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="mt-4">{{ $categories->links() }}</div>
            @endif
        </x-card>
    </div>
</x-app-layout>