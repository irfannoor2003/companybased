<x-app-layout :pageTitle="'Add payment method'">
    <x-slot name="header">
        <x-page-header title="Add payment method" description="Create a payment method accepted at the till." icon="money" />
    </x-slot>

    @include('pos._tabs')

    <div class="mt-6 max-w-3xl">
        <x-card>
            <form method="POST" action="{{ route('pos.payment_methods.store') }}" class="space-y-5">
                @csrf

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-input name="code" label="Code" required placeholder="e.g. MTN_MOMO" value="{{ old('code') }}" :error="$errors->first('code')" />
                    <x-input name="name" label="Name" required placeholder="e.g. MTN Mobile Money" value="{{ old('name') }}" :error="$errors->first('name')" />
                </div>

                <div class="flex flex-wrap gap-6">
                    <label class="flex items-center gap-2 text-sm text-ink-soft">
                        <x-toggle name="is_cash" :checked="old('is_cash')" />
                        Treats as cash
                    </label>
                    <label class="flex items-center gap-2 text-sm text-ink-soft">
                        <x-toggle name="is_active" :checked="old('is_active', true)" />
                        Active
                    </label>
                </div>

                <x-textarea name="notes" label="Notes">{{ old('notes') }}</x-textarea>

                <div class="flex items-center gap-2 pt-1">
                    <x-button type="submit" icon="save">Create method</x-button>
                    <x-button href="{{ route('pos.payment_methods.index') }}" variant="secondary">Cancel</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>