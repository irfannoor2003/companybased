<x-app-layout :pageTitle="'Payment '.$payment->number">
    <x-slot name="header">
        <x-page-header title="Payment {{ $payment->number }}" description="Customer payment voucher preview" icon="money">
            <x-slot name="actions">
                <x-button :href="route('sales.sales_payments.pdf', $payment)" variant="secondary" icon="download" target="_blank" rel="noopener">Download PDF</x-button>
                <button type="button" onclick="window.print()" class="btn-ghost btn-icon btn-sm" title="Print">
                    <x-icon name="printer" class="size-4" />
                </button>
                @if (auth()->user()->can('sales.sales_payments.edit'))
                    <x-button href="{{ route('sales.sales_payments.edit', $payment) }}" variant="secondary" icon="edit">Edit</x-button>
                @endif
                <x-button href="{{ route('sales.sales_payments.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-card :padding="false">
                <div class="border-b border-line px-5 py-4">
                    <h2 class="text-sm font-semibold text-ink">Payment receipt</h2>
                    <p class="mt-1 text-xs text-ink-faint">#{{ $payment->number }}</p>
                </div>
                <dl class="divide-y divide-line px-5 py-2 text-sm">
                    <div class="flex justify-between py-3">
                        <dt class="text-ink-faint">Received from</dt>
                        <dd class="font-medium text-ink">{{ $payment->customer?->company_name ?: '—' }}</dd>
                    </div>
                    @if ($payment->customer?->short_code)
                        <div class="flex justify-between py-3">
                            <dt class="text-ink-faint">Customer code</dt>
                            <dd class="font-medium text-ink">{{ $payment->customer->short_code }}</dd>
                        </div>
                    @endif
                    <div class="flex justify-between py-3">
                        <dt class="text-ink-faint">Payment date</dt>
                        <dd class="font-medium text-ink">{{ $payment->payment_date?->format('Y-m-d') ?: '—' }}</dd>
                    </div>
                    <div class="flex justify-between py-3">
                        <dt class="text-ink-faint">Method</dt>
                        <dd class="font-medium text-ink">{{ ucfirst(str_replace('_', ' ', $payment->method)) }}</dd>
                    </div>
                    @if ($payment->reference)
                        <div class="flex justify-between py-3">
                            <dt class="text-ink-faint">Reference</dt>
                            <dd class="font-medium text-ink">{{ $payment->reference }}</dd>
                        </div>
                    @endif
                    <div class="flex justify-between py-3">
                        <dt class="text-ink-faint">Received into account</dt>
                        <dd class="font-medium text-ink">{{ $payment->bankAccount?->name ?: '—' }}@if($payment->bankAccount?->bank_name) · {{ $payment->bankAccount->bank_name }}@endif</dd>
                    </div>
                    <div class="flex justify-between py-3">
                        <dt class="text-ink-faint">Against invoice</dt>
                        <dd class="font-medium text-ink">{{ $payment->invoice?->number ?: '—' }}</dd>
                    </div>
                    <div class="flex justify-between py-3">
                        <dt class="text-ink-faint">Currency</dt>
                        <dd class="font-medium text-ink">{{ $payment->currency ?: '—' }}</dd>
                    </div>
                </dl>
            </x-card>
        </div>

        <div class="space-y-6">
            <x-card title="Amount">
                <p class="text-2xl font-bold text-primary">{{ money($payment->amount, $payment->currency) }}</p>
            </x-card>

            @if ($payment->notes)
                <x-card title="Notes">
                    <p class="text-sm text-ink-soft">{{ $payment->notes }}</p>
                </x-card>
            @endif
        </div>
    </div>
</x-app-layout>
