<x-app-layout :pageTitle="'Purchase invoice '.$invoice->number">
    <x-slot name="header">
        <x-page-header title="Purchase invoice {{ $invoice->number }}" description="{{ $invoice->supplier?->company_name }}" icon="invoice">
            <x-slot name="actions">
                <div class="flex items-center gap-2">
                    <x-document-preview type="Purchase Invoice" number="{{ $invoice->number }}" customerName="{{ $invoice->supplier?->company_name }}" issueDate="{{ $invoice->issue_date }}" dueDate="{{ $invoice->due_date }}" currency="{{ $invoice->currency }}" notes="{{ $invoice->notes }}" />
                    @if (auth()->user()->can('suppliers.purchase_invoices.view'))
                        <x-button :href="route('suppliers.purchase_invoices.show', $invoice)" variant="secondary" icon="eye" target="_blank" rel="noopener">View / Print</x-button>
                    @endif
                    @if (auth()->user()->can('suppliers.debit_notes.create') && $invoice->balance() > 0)
                        <x-button href="{{ route('suppliers.debit_notes.create', ['invoice' => $invoice->id]) }}" variant="secondary" icon="credit">Debit note</x-button>
                    @endif
                    <x-button href="{{ route('suppliers.purchase_invoices.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
                </div>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-card title="Invoice details">
                <form method="POST" action="{{ route('suppliers.purchase_invoices.update', $invoice) }}" class="space-y-5">
                    @csrf
                    @method('PUT')

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3" x-data="currencyFromEntity()" x-init="initCurrencySelect()">
                    <x-select name="supplier_id" label="Supplier" required @change="$event.target.value && syncCurrency($event.target)">
                        @foreach ($suppliers as $supplier)
                            <option value="{{ $supplier->id }}" data-currency="{{ $supplier->currency ?? '' }}" @selected(old('supplier_id', $invoice->supplier_id) == $supplier->id)>{{ $supplier->company_name }}</option>
                        @endforeach
                        </x-select>
                        <x-select name="status" label="Status">
                            @foreach (\App\Models\PurchaseInvoice::statusOptions() as $status)
                                <option value="{{ $status }}" @selected(old('status', $invoice->status) === $status)>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
                            @endforeach
                        </x-select>
                        <x-select name="currency" label="Currency" x-ref="currency">
                        <option value="">— Default —</option>
                        @foreach (currency_options() as $code => $label)
                            <option value="{{ $code }}" @selected(old('currency', $invoice->currency) === $code)>{{ $label }}</option>
                        @endforeach
                    </x-select>
                        <x-input name="issue_date" label="Issue date" type="date" value="{{ old('issue_date', $invoice->issue_date?->format('Y-m-d')) }}" />
                        <x-input name="due_date" label="Due date" type="date" value="{{ old('due_date', $invoice->due_date?->format('Y-m-d')) }}" />
                    </div>

                    @php
                        $initialItems = $invoice->items->map(fn ($item) => [
                            'product_id' => (string) ($item->product_id ?? ''),
                            'description' => $item->description,
                            'qty' => (float) $item->qty,
                            'unit_price' => (float) $item->unit_price,
                            'discount_percent' => (float) $item->discount_percent,
                            'tax_percent' => (float) $item->tax_percent,
                        ])->all();
                    @endphp

                    <x-suppliers.line-items-editor :products="$products" :initial-items="$initialItems" :currency="$invoice->currency" />

                    <x-textarea name="notes" label="Notes" rows="3">{{ old('notes', $invoice->notes) }}</x-textarea>

                    <div class="flex justify-end gap-3 border-t border-line pt-4">
                        <x-button type="submit" icon="save">Save changes</x-button>
                    </div>
                </form>
            </x-card>
        </div>

        <div class="space-y-6">
            <x-card title="Summary">
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-ink-faint">Subtotal</dt><dd class="text-ink">{{ money($invoice->subtotal, $invoice->currency) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-faint">Tax</dt><dd class="text-ink">{{ money($invoice->tax_amount, $invoice->currency) }}</dd></div>
                    <div class="flex justify-between border-t border-line pt-2"><dt class="text-ink font-semibold">Total</dt><dd class="font-semibold text-ink">{{ money($invoice->total, $invoice->currency) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-faint">Paid</dt><dd class="font-medium text-emerald-600 dark:text-emerald-400">{{ money($invoice->paid_amount, $invoice->currency) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-faint">Balance</dt><dd class="font-medium {{ $invoice->balance() > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400' }}">{{ money($invoice->balance(), $invoice->currency) }}</dd></div>
                </dl>
                <div class="mt-4">
                    <x-suppliers.status-badge :status="$invoice->status" />
                </div>
            </x-card>

            @if (auth()->user()->can('suppliers.purchase_invoices.record_payment') && $invoice->balance() > 0)
                <x-card title="Record payment">
                    <form method="POST" action="{{ route('suppliers.purchase_invoices.payments.store', $invoice) }}" class="space-y-3">
                        @csrf
                        <div class="grid grid-cols-2 gap-3">
                            <x-input name="amount" label="Amount" type="number" step="0.01" min="0.01" :value="$invoice->balance()" required />
                            <x-input name="payment_date" label="Date" type="date" value="{{ old('payment_date', now()->toDateString()) }}" required />
                        </div>
                        <x-select name="method" label="Method">
                            @foreach (\App\Models\SupplierPayment::methodOptions() as $method)
                                <option value="{{ $method }}" @selected(old('method', 'bank_transfer') === $method)>{{ ucwords(str_replace('_', ' ', $method)) }}</option>
                            @endforeach
                        </x-select>
                        <x-input name="reference" label="Reference" value="{{ old('reference') }}" placeholder="e.g. bank ref, cheque no." />
                        <x-button type="submit" icon="save" class="w-full">Record payment</x-button>
                    </form>
                </x-card>
            @endif

            @if ($invoice->payments->isNotEmpty())
                <x-card title="Payments" :padding="false">
                    <div class="divide-y divide-line">
                        @foreach ($invoice->payments as $payment)
                            <div class="flex items-center justify-between gap-3 px-5 py-3">
                                <div>
                                    <p class="text-sm font-medium text-ink">{{ money($payment->amount, $invoice->currency) }}</p>
                                    <p class="text-xs text-ink-faint">{{ $payment->payment_date?->format('Y-m-d') }} · {{ ucwords(str_replace('_', ' ', $payment->method)) }}{{ $payment->reference ? ' · '.$payment->reference : '' }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </x-card>
            @endif

            @if ($invoice->debitNotes->isNotEmpty())
                <x-card title="Debit notes" :padding="false">
                    <div class="divide-y divide-line">
                        @foreach ($invoice->debitNotes as $note)
                            <a href="{{ route('suppliers.debit_notes.edit', $note) }}" class="flex items-center justify-between gap-3 px-5 py-3 hover:bg-surface-muted/40">
                                <span class="text-sm font-medium text-ink">{{ $note->number }}</span>
                                <span class="text-sm text-ink-soft">{{ money($note->total, $invoice->currency) }}</span>
                            </a>
                        @endforeach
                    </div>
                </x-card>
            @endif

            <x-card title="Timeline">
                @forelse ($invoice->statusEvents->reverse() as $event)
                    <div class="flex gap-3 border-l border-line pb-4 pl-4 last:border-0 last:pb-0">
                        <div class="relative -ml-[21px] mt-1 size-3 shrink-0 rounded-full border-2 border-line bg-surface"></div>
                        <div>
                            <p class="text-sm text-ink">
                                {{ $event->from_status ? ucfirst(str_replace('_', ' ', $event->from_status)).' → ' : '' }}<span class="font-medium">{{ ucfirst(str_replace('_', ' ', $event->to_status)) }}</span>
                            </p>
                            @if ($event->note)
                                <p class="text-xs text-ink-soft">{{ $event->note }}</p>
                            @endif
                            <p class="mt-0.5 text-xs text-ink-faint">{{ $event->created_at->format('M j, H:i') }} · {{ $event->user?->name ?: 'System' }}</p>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-ink-faint">No status changes yet.</p>
                @endforelse
            </x-card>
        </div>
    </div>
</x-app-layout>
