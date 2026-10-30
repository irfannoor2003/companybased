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

    {{--
        The voucher preview, laid out like the invoice show screen
        (sales/invoices/show.blade.php): the same letterhead row, the same
        right-aligned document block, the same section rhythm and the same
        wireframe for print. The show screens of the money documents being two
        different species is what read as a broken page; they are now one layout
        with different content.

        The screen is a preview of the receipt, not the receipt itself: the
        actual printed document is produced from the receipt template and stays
        its own design (see app/Support/ReceiptDocument.php).
    --}}
    <div class="mt-6 max-w-4xl">
        <div class="invoice-document">
            <div class="flex justify-between border-b border-line pb-4 mb-6">
                <div>
                    @if (settings('branding.logo'))
                        <img src="{{ Storage::url(settings('branding.logo')) }}" alt="{{ company_name() }}" class="h-20">
                    @else
                        <span class="text-xl font-bold text-ink">{{ company_name() }}</span>
                    @endif
                    <p class="text-sm text-ink-faint mt-1">{{ settings('company.address') ?: '' }}</p>
                    <p class="text-sm text-ink-faint">{{ settings('company.email') ?: '' }}</p>
                </div>
                <div class="text-right">
                    <h2 class="text-2xl font-bold text-ink">Payment Receipt</h2>
                    <p class="text-sm text-ink-soft mt-1">{{ $payment->number }}</p>
                    <p class="text-sm text-ink-faint">Payment date: {{ $payment->payment_date?->format('Y-m-d') ?: '—' }}</p>
                    @if ($payment->invoice)
                        <p class="text-sm text-ink-faint">Against invoice: {{ $payment->invoice->number }}</p>
                    @endif
                </div>
            </div>

            <div class="mb-6">
                <h3 class="text-sm font-semibold text-ink-faint uppercase mb-2">Received from</h3>
                <p class="font-medium text-ink">{{ $payment->customer?->company_name ?: '—' }}</p>
                @if ($payment->customer?->short_code)
                    <p class="text-sm text-ink-soft">Code: {{ $payment->customer->short_code }}</p>
                @endif
            </div>

            {{-- The hero figure, mirroring the amount box the printed receipt
                 carries: the number someone is paying for appears first. --}}
            <div class="mb-6 rounded-xl border border-primary/15 bg-primary/5 px-5 py-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-ink-faint">Amount received</p>
                <p class="mt-1 text-2xl font-bold text-primary-ink">{{ money($payment->amount, $payment->currency) }}</p>
            </div>

            <dl class="mb-6 space-y-2.5 text-sm">
                <div class="flex justify-between">
                    <dt class="text-ink-faint">Received into account</dt>
                    <dd class="font-medium text-ink">{{ $payment->bankAccount?->name ?: '—' }}@if($payment->bankAccount?->bank_name) · {{ $payment->bankAccount->bank_name }}@endif</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-ink-faint">Method</dt>
                    <dd class="font-medium text-ink">{{ ucfirst(str_replace('_', ' ', $payment->method)) }}</dd>
                </div>
                @if ($payment->reference)
                    <div class="flex justify-between">
                        <dt class="text-ink-faint">Reference</dt>
                        <dd class="font-medium text-ink">{{ $payment->reference }}</dd>
                    </div>
                @endif
                <div class="flex justify-between">
                    <dt class="text-ink-faint">Currency</dt>
                    <dd class="font-medium text-ink">{{ $payment->currency ?: '—' }}</dd>
                </div>
            </dl>

            @if ($payment->notes)
                <div class="mb-6">
                    <h3 class="text-sm font-semibold text-ink-faint uppercase mb-2">Notes</h3>
                    <p class="text-sm text-ink">{{ $payment->notes }}</p>
                </div>
            @endif

            <div class="border-t border-line pt-4 text-center text-xs text-ink-faint">
                <p>Payment received with thanks.</p>
            </div>
        </div>
    </div>
</x-app-layout>
