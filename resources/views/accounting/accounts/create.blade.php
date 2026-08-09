<x-app-layout :pageTitle="'New account'">
    <x-slot name="header">
        <x-page-header title="New account" description="Add an account to the chart of accounts." icon="plus" />
    </x-slot>

    @include('accounting._tabs')

    <div class="mt-6 max-w-2xl">
        <x-card>
            <form method="POST" action="{{ route('accounting.accounts.store') }}" class="space-y-4">
                @csrf

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-input name="code" label="Code" required placeholder="e.g. 1100" value="{{ old('code') }}" />
                    <x-input name="name" label="Name" required placeholder="e.g. Accounts receivable" value="{{ old('name') }}" />
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <x-select name="type" label="Type" required>
                            @foreach (\App\Models\Account::typeOptions() as $type)
                                <option value="{{ $type }}" @selected(old('type') === $type || (! old('type') && $type === 'expense'))>{{ \App\Models\Account::typeLabel($type) }}</option>
                            @endforeach
                        </x-select>
                    </div>
                    <x-input name="sub_type" label="Sub type" placeholder="e.g. Current asset" value="{{ old('sub_type') }}" />
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <x-select name="parent_id" label="Parent account">
                            <option value="">None</option>
                            @foreach ($parents as $parent)
                                <option value="{{ $parent->id }}" @selected(old('parent_id') == $parent->id)>{{ $parent->code }} — {{ $parent->name }}</option>
                            @endforeach
                        </x-select>
                    </div>
                    <x-input name="currency" label="Currency" required value="{{ old('currency', settings('company.currency', 'USD')) }}" />
                </div>

                <x-toggle name="is_active" label="Active" checked="true" />

                <x-textarea name="description" label="Description" value="{{ old('description') }}" />

                <div class="flex items-center gap-2 pt-2">
                    <x-button type="submit" icon="save">Create account</x-button>
                    <x-button href="{{ route('accounting.accounts.index') }}" variant="secondary">Cancel</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
