<x-app-layout :pageTitle="$tax->number">
    <x-slot name="header">
        <x-page-header :title="$tax->number" :description="ucfirst($tax->tax_type).' · '.$tax->period_label" icon="tax">
            <x-slot name="actions">
                @if (auth()->user()->can('accounting.tax_returns.edit') && $tax->status !== 'paid')
                    <x-button href="{{ route('accounting.tax_returns.edit', $tax) }}" variant="secondary" icon="edit">Edit</x-button>
                @endif
                <x-button href="{{ route('accounting.tax_returns.index') }}" variant="ghost" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    @include('accounting._tabs')

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat-card label="Taxable amount" :value="money($tax->taxable_amount, $tax->currency)" icon="tax" tone="primary" />
        <x-stat-card label="Tax collected" :value="money($tax->tax_collected, $tax->currency)" icon="arrow-down" tone="info" />
        <x-stat-card label="Tax credits" :value="money($tax->tax_credits, $tax->currency)" icon="arrow-up" tone="warning" />
        <x-stat-card label="Tax due" :value="money($tax->tax_due, $tax->currency)" icon="money" tone="danger" />
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-card title="Return details" class="lg:col-span-2">
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between"><dt class="text-ink-faint">Number</dt><dd class="font-mono text-ink">{{ $tax->number }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-faint">Type</dt><dd class="text-ink">{{ ucfirst($tax->tax_type) }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-faint">Period</dt><dd class="text-ink">{{ $tax->period_start->format('Y-m-d') }} → {{ $tax->period_end->format('Y-m-d') }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-faint">Gross receipts</dt><dd class="text-ink">{{ money($tax->gross_receipts, $tax->currency) }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-faint">Taxable amount</dt><dd class="text-ink">{{ money($tax->taxable_amount, $tax->currency) }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-faint">Tax collected</dt><dd class="text-ink">{{ money($tax->tax_collected, $tax->currency) }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-faint">Tax credits</dt><dd class="text-ink">{{ money($tax->tax_credits, $tax->currency) }}</dd></div>
                <div class="flex justify-between border-t border-line pt-3"><dt class="font-semibold text-ink">Tax due</dt><dd class="font-semibold text-ink">{{ money($tax->tax_due, $tax->currency) }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-faint">Filed at</dt><dd class="text-ink">{{ $tax->filed_at?->format('Y-m-d') ?: '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-faint">Paid at</dt><dd class="text-ink">{{ $tax->paid_at?->format('Y-m-d') ?: '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-faint">Status</dt><dd><x-accounting.status-badge :status="$tax->status" /></dd></div>
            </dl>
        </x-card>

        @if (auth()->user()->can('accounting.tax_returns.edit') && $tax->status !== 'paid')
            <x-card title="File / pay" description="Advance the return through its lifecycle">
                <form method="POST" action="{{ route('accounting.tax_returns.status', $tax) }}" class="space-y-3">
                    @csrf
                    <x-select name="status" label="Action" size="sm">
                        <option value="filed" @selected($tax->status === 'filed')>File return</option>
                        <option value="paid" @selected($tax->status === 'paid')>Mark as paid</option>
                    </x-select>
                    <x-button type="submit" size="sm" icon="check">Update</x-button>
                </form>
            </x-card>
        @endif
    </div>
</x-app-layout>