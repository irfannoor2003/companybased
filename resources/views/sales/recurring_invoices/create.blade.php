<x-app-layout :pageTitle="'New recurring invoice'">
    <x-slot name="header">
        <x-page-header title="New recurring invoice" description="Set up a billing template that repeats automatically." icon="repeat">
            <x-slot name="actions">
                <x-button href="{{ route('sales.recurring_invoices.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 max-w-6xl">
        <x-card title="Recurring invoice details">
            <form method="POST" action="{{ route('sales.recurring_invoices.store') }}" class="space-y-5">
                @csrf

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    <x-input name="name" label="Template name" required value="{{ old('name') }}" placeholder="e.g. Monthly hosting fee" />
                    <x-select name="customer_id" label="Customer" required>
                        <option value="">— Select customer —</option>
                        @foreach ($customers as $customer)
                            <option value="{{ $customer->id }}" @selected(old('customer_id') == $customer->id)>{{ $customer->company_name }}</option>
                        @endforeach
                    </x-select>
                    <x-select name="frequency" label="Frequency">
                        @foreach (\App\Models\SalesRecurringInvoice::frequencyOptions() as $frequency)
                            <option value="{{ $frequency }}" @selected(old('frequency', 'monthly') === $frequency)>{{ ucfirst($frequency) }}</option>
                        @endforeach
                    </x-select>
                    <x-input name="next_run_date" label="Next run date" type="date" value="{{ old('next_run_date', now()->toDateString()) }}" />
                    <x-input name="day_of_cycle" label="Day of cycle" type="number" min="1" max="31" value="{{ old('day_of_cycle', 1) }}" hint="1-31. Clamped to month length." />
                    <x-input name="currency" label="Currency" value="{{ old('currency', 'USD') }}" placeholder="USD, EUR…" />
                </div>

                <x-sales.line-items-editor :products="$products" :initial-items="[]" :currency="old('currency', 'USD')" />

                <x-textarea name="notes" label="Notes" rows="3">{{ old('notes') }}</x-textarea>

                <div class="border-t border-line pt-4">
                    <x-toggle name="is_active" label="Active" description="Inactive templates do not generate invoices." :checked="old('is_active', true)" />
                </div>

                <div class="flex justify-end gap-3 border-t border-line pt-4">
                    <x-button href="{{ route('sales.recurring_invoices.index') }}" variant="ghost">Cancel</x-button>
                    <x-button type="submit" icon="save">Create template</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
