<x-app-layout :pageTitle="'Edit payment method'">
    <x-slot name="header">
        <x-page-header title="Edit payment method" description="Update the payment method." icon="money" />
    </x-slot>

    @include('pos._tabs')

    <div class="mt-6 max-w-3xl">
        <x-card>
            <form method="POST" action="{{ route('pos.payment_methods.update', $paymentMethod) }}" class="space-y-5">
                @csrf
                @method('PUT')

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-input name="code" label="Code" required value="{{ old('code', $paymentMethod->code) }}" :error="$errors->first('code')" />
                    <x-input name="name" label="Name" required value="{{ old('name', $paymentMethod->name) }}" :error="$errors->first('name')" />
                </div>

                <div class="flex flex-wrap gap-6">
                    <label class="flex items-center gap-2 text-sm text-ink-soft">
                        <x-toggle name="is_cash" :checked="old('is_cash', $paymentMethod->is_cash)" />
                        Treats as cash
                    </label>
                    <label class="flex items-center gap-2 text-sm text-ink-soft">
                        <x-toggle name="is_active" :checked="old('is_active', $paymentMethod->is_active)" />
                        Active
                    </label>
                </div>

                <x-textarea name="notes" label="Notes">{{ old('notes', $paymentMethod->notes) }}</x-textarea>

                <div class="flex items-center gap-2 pt-1">
                    <x-button type="submit" icon="save">Update method</x-button>
                    <x-button href="{{ route('pos.payment_methods.index') }}" variant="secondary">Cancel</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>