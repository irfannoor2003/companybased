<x-app-layout :pageTitle="'Edit expense category'">
    <x-slot name="header">
        <x-page-header title="Edit expense category" description="{{ $category->name }}" icon="document" />
    </x-slot>

    @include('accounting._tabs')

    <div class="mt-6 max-w-2xl">
        <x-card>
            <form method="POST" action="{{ route('accounting.expense_categories.update', $category) }}" class="space-y-4">
                @csrf
                @method('PUT')
                <x-input name="name" label="Category name" required value="{{ old('name', $category->name) }}" />
                <div>
                    <x-select name="account_id" label="Expense account (chart of accounts)">
                        <option value="">— None (uses the default expense account) —</option>
                        @foreach ($accounts as $account)
                            <option value="{{ $account->id }}" @selected(old('account_id', $category->account_id) == $account->id)>{{ $account->code }} — {{ $account->name }}</option>
                        @endforeach
                    </x-select>
                    <p class="mt-1 text-xs text-ink-faint">
                        Where these expenses post in the ledger. An approved expense claim under this
                        category is debited to this account; bill lines post against it as well.
                        Leave it empty to use the app's default expense account.
                    </p>
                </div>
                <x-textarea name="description" label="Description" rows="3">{{ old('description', $category->description) }}</x-textarea>
                <x-toggle name="is_active" label="Active" :checked="old('is_active', $category->is_active)" />
                <div class="flex items-center gap-2 pt-2">
                    <x-button type="submit" icon="save">Save changes</x-button>
                    <x-button href="{{ route('accounting.expense_categories.index') }}" variant="secondary">Cancel</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>