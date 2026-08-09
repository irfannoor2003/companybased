<x-app-layout :pageTitle="'Edit account'">
    <x-slot name="header">
        <x-page-header title="Edit account" :description="$account->code.' '.$account->name" icon="edit" />
    </x-slot>

    @include('accounting._tabs')

    <div class="mt-6 max-w-2xl">
        <x-card>
            <form method="POST" action="{{ route('accounting.accounts.update', $account) }}" class="space-y-4">
                @csrf
                @method('PUT')

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-input name="code" label="Code" required placeholder="e.g. 1100" value="{{ old('code', $account->code) }}" />
                    <x-input name="name" label="Name" required value="{{ old('name', $account->name) }}" />
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <x-select name="type" label="Type" required>
                            @foreach (\App\Models\Account::typeOptions() as $type)
                                <option value="{{ $type }}" @selected(old('type', $account->type) === $type)>{{ \App\Models\Account::typeLabel($type) }}</option>
                            @endforeach
                        </x-select>
                    </div>
                    <x-input name="sub_type" label="Sub type" value="{{ old('sub_type', $account->sub_type) }}" />
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <x-select name="parent_id" label="Parent account">
                            <option value="">None</option>
                            @foreach ($parents as $parent)
                                <option value="{{ $parent->id }}" @selected(old('parent_id', $account->parent_id) == $parent->id)>{{ $parent->code }} — {{ $parent->name }}</option>
                            @endforeach
                        </x-select>
                    </div>
                    <x-input name="currency" label="Currency" required value="{{ old('currency', $account->currency) }}" />
                </div>

                <x-toggle name="is_active" label="Active" :checked="$account->is_active" />

                <x-textarea name="description" label="Description" value="{{ old('description', $account->description) }}" />

                <div class="flex items-center gap-2 pt-2">
                    <x-button type="submit" icon="save">Save changes</x-button>
                    <x-button href="{{ route('accounting.accounts.show', $account) }}" variant="secondary">Cancel</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
