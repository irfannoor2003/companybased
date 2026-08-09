<x-app-layout :pageTitle="$bill->number">
    <x-slot name="header">
        <x-page-header :title="$bill->number" :description="$bill->vendor_name.' · '.$bill->bill_date->format('Y-m-d')" icon="invoice">
            <x-slot name="actions">
                @if (auth()->user()->can('accounting.bills.edit') && ! in_array($bill->status, ['paid', 'void']))
                    <x-button href="{{ route('accounting.bills.edit', $bill) }}" variant="secondary" icon="edit">Edit</x-button>
                @endif
                <x-button href="{{ route('accounting.bills.index') }}" variant="ghost" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    @include('accounting._tabs')

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat-card label="Amount" :value="money($bill->amount, $bill->currency)" icon="invoice" tone="primary" />
        <x-stat-card label="Paid" :value="money($bill->paid_amount, $bill->currency)" icon="check" tone="success" />
        <x-stat-card label="Balance" :value="money($bill->balance(), $bill->currency)" icon="clock" :tone="$bill->balance() > 0 ? 'warning' : 'success'" />
        <x-stat-card label="Status" :value="ucfirst(str_replace('_', ' ', $bill->status))" icon="tag" :tone="$bill->status === 'paid' ? 'success' : ($bill->status === 'void' ? 'danger' : 'info')" />
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-card title="Line items" :padding="false" class="lg:col-span-2">
            @if ($bill->items->isEmpty())
                <x-empty-state icon="document" title="No line items" description="This bill has no itemized lines." />
            @else
                <div class="table-wrap !border-0 !rounded-none">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th>Account</th>
                                <th>Description</th>
                                <th class="text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($bill->items as $item)
                                <tr>
                                    <td>
                                        <span class="font-medium text-ink">{{ $item->account?->name }}</span>
                                        <span class="block font-mono text-xs text-ink-faint">{{ $item->account?->code }}</span>
                                    </td>
                                    <td class="text-ink-soft">{{ $item->description ?: '—' }}</td>
                                    <td class="text-right font-medium text-ink">{{ money($item->amount, $bill->currency) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="2" class="text-right font-semibold text-ink">Total</td>
                                <td class="text-right font-semibold text-ink">{{ money($bill->amount, $bill->currency) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </x-card>

        <div class="space-y-6">
            <x-card title="Bill details">
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between"><dt class="text-ink-faint">Number</dt><dd class="font-mono text-ink">{{ $bill->number }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-faint">Vendor</dt><dd class="text-ink">{{ $bill->vendor_name }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-faint">Bill date</dt><dd class="text-ink">{{ $bill->bill_date->format('Y-m-d') }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-faint">Due date</dt><dd class="text-ink">{{ $bill->due_date?->format('Y-m-d') ?: '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-faint">Reference</dt><dd class="text-ink">{{ $bill->reference ?: '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-faint">Status</dt><dd><x-accounting.status-badge :status="$bill->status" /></dd></div>
                </dl>
            </x-card>

            @if (auth()->user()->can('accounting.bills.record_payment') && ! in_array($bill->status, ['paid', 'void']))
                <x-card title="Record payment" description="Apply against the outstanding balance">
                    <form method="POST" action="{{ route('accounting.bills.payment', $bill) }}" class="space-y-3">
                        @csrf
                        <div class="grid grid-cols-2 gap-3">
                            <x-input name="amount" label="Amount" type="number" step="0.01" min="0" required value="{{ old('amount', $bill->balance()) }}" />
                            <x-input name="paid_at" label="Date" type="date" required value="{{ old('paid_at', now()->toDateString()) }}" />
                        </div>
                        <x-button type="submit" size="sm" icon="check">Record payment</x-button>
                    </form>
                </x-card>
            @endif

            @if (auth()->user()->can('accounting.bills.edit') && ! in_array($bill->status, ['paid', 'void']))
                <x-card title="Void bill" description="Permanently cancel this bill">
                    <form method="POST" action="{{ route('accounting.bills.status', $bill) }}"
                        onsubmit="return confirm('Void bill {{ $bill->number }}?');">
                        @csrf
                        <input type="hidden" name="status" value="void">
                        <x-button type="submit" variant="danger-secondary" size="sm" icon="x">Void bill</x-button>
                    </form>
                </x-card>
            @endif
        </div>
    </div>
</x-app-layout>