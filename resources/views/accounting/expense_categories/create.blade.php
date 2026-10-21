<x-app-layout :pageTitle="'New expense category'">
    <x-slot name="header">
        <x-page-header title="New expense category" description="Add a category to organise your bill lines." icon="plus" />
    </x-slot>

    @include('accounting._tabs')

    <div class="mt-6 max-w-2xl">
        <x-card>
            <form method="POST" action="{{ route('accounting.expense_categories.store') }}" class="space-y-4">
                @csrf
                <x-input name="name" label="Category name" required value="{{ old('name') }}" />
                <x-select name="account_id" label="Expense account (chart of accounts)">
                    <option value="">— None (defaults to COGS) —</option>
                    @foreach ($accounts as $account)
                        <option value="{{ $account->id }}" @selected(old('account_id') == $account->id)>{{ $account->code }} — {{ $account->name }}</option>
                    @endforeach
                </x-select>
                <x-textarea name="description" label="Description" rows="3">{{ old('description') }}</x-textarea>
                <x-toggle name="is_active" label="Active" :checked="old('is_active', true)" />
                <div class="flex items-center gap-2 pt-2">
                    <x-button type="submit" icon="save">Create category</x-button>
                    <x-button href="{{ route('accounting.expense_categories.index') }}" variant="secondary">Cancel</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>