<x-app-layout :pageTitle="'Edit recurring invoice'">
    <x-slot name="header">
        <x-page-header title="{{ $recurringInvoice->name }}" description="{{ $recurringInvoice->customer?->company_name }}" icon="repeat">
            <x-slot name="actions">
                <x-button href="{{ route('sales.recurring_invoices.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-card title="Recurring invoice details">
                <form method="POST" action="{{ route('sales.recurring_invoices.update', $recurringInvoice) }}" class="space-y-5">
                    @csrf
                    @method('PUT')

                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                        <x-input name="name" label="Template name" required value="{{ old('name', $recurringInvoice->name) }}" />
                        <x-select name="customer_id" label="Customer" required>
                            @foreach ($customers as $customer)
                                <option value="{{ $customer->id }}" @selected(old('customer_id', $recurringInvoice->customer_id) == $customer->id)>{{ $customer->company_name }}</option>
                            @endforeach
                        </x-select>
                        <x-select name="frequency" label="Frequency">
                            @foreach (\App\Models\SalesRecurringInvoice::frequencyOptions() as $frequency)
                                <option value="{{ $frequency }}" @selected(old('frequency', $recurringInvoice->frequency) === $frequency)>{{ ucfirst($frequency) }}</option>
                            @endforeach
                        </x-select>
                        <x-input name="next_run_date" label="Next run date" type="date" value="{{ old('next_run_date', $recurringInvoice->next_run_date?->format('Y-m-d')) }}" />
                        <x-input name="day_of_cycle" label="Day of cycle" type="number" min="1" max="31" value="{{ old('day_of_cycle', $recurringInvoice->day_of_cycle) }}" />
                        <x-select name="currency" label="Currency">
                        <option value="">— Default —</option>
                        @foreach (currency_options() as $code => $label)
                            <option value="{{ $code }}" @selected(old('currency', 'currency', $recurringInvoice->currency) === $code)>{{ $label }}</option>
                        @endforeach
                    </x-select>
                    </div>

                    @php
                        $initialItems = $recurringInvoice->items->map(fn ($item) => [
                            'product_id' => (string) ($item->product_id ?? ''),
                            'description' => $item->description,
                            'qty' => (float) $item->qty,
                            'unit_price' => (float) $item->unit_price,
                            'discount_percent' => (float) $item->discount_percent,
                            'tax_percent' => (float) $item->tax_percent,
                        ])->all();
                    @endphp

                    <x-sales.line-items-editor :products="$products" :initial-items="$initialItems" :currency="$recurringInvoice->currency" />

                    <x-textarea name="notes" label="Notes" rows="3">{{ old('notes', $recurringInvoice->notes) }}</x-textarea>

                    <div class="border-t border-line pt-4">
                        <x-toggle name="is_active" label="Active" description="Inactive templates do not generate invoices." :checked="old('is_active', $recurringInvoice->is_active)" />
                    </div>

                    <div class="flex justify-end gap-3 border-t border-line pt-4">
                        <x-button type="submit" icon="save">Save changes</x-button>
                    </div>
                </form>
            </x-card>
        </div>

        <div class="space-y-6">
            <x-card title="Summary">
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-ink-faint">Subtotal</dt><dd class="text-ink">{{ money($recurringInvoice->subtotal, $recurringInvoice->currency) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-faint">Tax</dt><dd class="text-ink">{{ money($recurringInvoice->tax_amount, $recurringInvoice->currency) }}</dd></div>
                    <div class="flex justify-between border-t border-line pt-2 text-base font-semibold"><dt class="text-ink">Amount / cycle</dt><dd class="text-ink">{{ money($recurringInvoice->total, $recurringInvoice->currency) }}</dd></div>
                </dl>
                <div class="mt-4">
                    <x-sales.status-badge :status="$recurringInvoice->is_active ? 'active' : 'inactive'" />
                </div>
            </x-card>
        </div>
    </div>
</x-app-layout>
