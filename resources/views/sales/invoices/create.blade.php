<x-app-layout :pageTitle="'New invoice'">
    <x-slot name="header">
        <x-page-header title="New invoice" description="Create an invoice for a customer." icon="invoice">
            <x-slot name="actions">
                <div class="flex items-center gap-2">
                    <x-document-preview type="Invoice" number="Draft" currency="{{ old('currency', $fromOrder?->currency ?? settings('company.currency', 'USD')) }}" />
                    <x-button href="{{ route('sales.invoices.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
                </div>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 max-w-6xl">
        <x-card title="Invoice details">
            <form method="POST" action="{{ route('sales.invoices.store') }}" class="space-y-5">
                @csrf

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3" x-data="currencyFromEntity()" x-init="initCurrencySelect()">
                    <x-select name="customer_id" label="Customer" required @change="$event.target.value && syncCurrency($event.target)">
                        <option value="">— Select customer —</option>
                        @foreach ($customers as $customer)
                            <option value="{{ $customer->id }}" data-currency="{{ $customer->currency ?? '' }}" @selected(old('customer_id', $fromOrder?->customer_id) == $customer->id)>{{ $customer->company_name }}</option>
                        @endforeach
                    </x-select>
                    @if ($fromOrder)
                        <input type="hidden" name="order_id" value="{{ $fromOrder->id }}">
                        <div class="flex items-end">
                            <a href="{{ route('sales.orders.edit', $fromOrder) }}" class="inline-flex items-center gap-2 rounded-lg border border-line px-3 py-2 text-sm text-ink-soft hover:border-primary/40">
                                <x-icon name="orders" class="size-4" />
                                Billing {{ $fromOrder->number }}
                            </a>
                        </div>

                        {{-- Stated plainly, because "Invoice" on an order that is already
                             billed should not lead anyone to expect a second one.

                             The second notice is keyed off whether ANY live invoice
                             exists, NOT off a comparison of line counts. Comparing
                             counts is wrong the moment one order line is partially
                             invoiced: one order line, one remaining line, counts
                             match, and the notice silently disappears -- which is
                             precisely the case a user most needs to be told about.

                             The count shown is what is left, not what is gone,
                             because "4 of 6 remain" is actionable and "2 have been
                             invoiced" requires the user to subtract it themselves. --}}
                        @if ($orderFullyInvoiced)
                            <x-alert type="warning" class="mt-3">
                                Order {{ $fromOrder->number }} has been invoiced in full
                                ({{ money($fromOrder->total, $fromOrder->currency) }}).
                            </x-alert>
                        @elseif ($fromOrder->liveInvoices->isNotEmpty())
                            <x-alert type="info" class="mt-3">
                                {{ count($fromOrderLines) }} of {{ count($fromOrder->items) }} line(s) remain
                                to be invoiced. Anything already invoiced is not shown below.
                            </x-alert>
                        @endif
                    @else
                        <x-select name="order_id" label="Related order">
                            <option value="">— None —</option>
                            @foreach (\App\Models\SalesOrder::query()->whereIn('status', ['confirmed', 'packed', 'shipped', 'delivered'])->orderByDesc('issue_date')->limit(50)->get() as $order)
                                <option value="{{ $order->id }}" @selected(old('order_id') == $order->id)>{{ $order->number }} · {{ $order->customer?->company_name }}</option>
                            @endforeach
                        </x-select>
                    @endif
                    <x-select name="status" label="Status">
                        @foreach (\App\Models\SalesInvoice::statusOptions() as $status)
                            <option value="{{ $status }}" @selected(old('status', 'sent') === $status)>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
                        @endforeach
                    </x-select>
                    @include('documents.partials.reference-number', ['example' => 'INV-2026-0001'])
                    <x-input name="issue_date" label="Issue date" type="date" value="{{ old('issue_date', now()->toDateString()) }}" />
                    <x-input name="due_date" label="Due date" type="date" value="{{ old('due_date') }}" />
                    <x-select name="currency" label="Currency" x-ref="currency">
                        <option value="">— Default —</option>
                        @foreach (currency_options() as $code => $label)
                            <option value="{{ $code }}" @selected(old('currency', $fromOrder?->currency ?? settings('company.currency', 'USD')) === $code)>{{ $label }}</option>
                        @endforeach
                    </x-select>
                </div>

                {{--
                    Prefilled from SalesOrder::uninvoicedLines(), so an order delivered
                    in parts bills only the remainder rather than re-billing what has
                    already been invoiced.
                --}}
                @php
                    $initialItems = $fromOrder
                        ? $fromOrderLines
                        : [];
                @endphp

                <x-sales.line-items-editor :products="$products" :initial-items="$initialItems" :currency="old('currency', $fromOrder?->currency ?? settings('company.currency', 'USD'))" :max-discount="$maxDiscount" :tax-hint="$taxHint" />

                <x-textarea name="notes" label="Notes" rows="3">{{ old('notes') }}</x-textarea>

                <div class="flex justify-end gap-3 border-t border-line pt-4">
                    <x-button href="{{ route('sales.invoices.index') }}" variant="ghost">Cancel</x-button>
                    <x-button type="submit" icon="save">Create invoice</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
