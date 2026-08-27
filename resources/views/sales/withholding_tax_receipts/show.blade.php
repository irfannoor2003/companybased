<x-app-layout :pageTitle="'Withholding Tax Receipt '.$withholdingTaxReceipt->number">
    <x-slot name="header">
        <x-page-header title="Withholding Tax Receipt {{ $withholdingTaxReceipt->number }}" description="{{ $withholdingTaxReceipt->customer?->company_name ?? '—' }}" icon="document">
            <x-slot name="actions">
                <div class="print:hidden">
                    <x-button href="{{ route('sales.withholding_tax_receipts.pdf', $withholdingTaxReceipt) }}" variant="secondary" icon="download" target="_blank" rel="noopener">Export PDF</x-button>
                    <button type="button" onclick="window.print()" class="btn-ghost btn-icon btn-sm" title="Print">
                        <x-icon name="printer" class="size-4" />
                    </button>
                    <x-button href="{{ route('sales.withholding_tax_receipts.edit', $withholdingTaxReceipt) }}" variant="secondary" icon="edit">Edit</x-button>
                </div>
                <x-button href="{{ route('sales.withholding_tax_receipts.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 max-w-4xl">
        <div class="document">
            <div class="flex justify-between border-b border-line pb-4 mb-6">
                <div>
                    @if (settings('branding.logo'))
                        <img src="{{ Storage::url(settings('branding.logo')) }}" alt="{{ company_name() }}" class="h-10">
                    @else
                        <span class="text-xl font-bold text-ink">{{ company_name() }}</span>
                    @endif
                    <p class="text-sm text-ink-faint mt-1">{{ settings('company.address') ?: '' }}</p>
                    <p class="text-sm text-ink-faint">{{ settings('company.email') ?: '' }}</p>
                </div>
                <div class="text-right">
                    <h2 class="text-2xl font-bold text-ink">Withholding Tax Receipt</h2>
                    <p class="text-sm text-ink-soft mt-1">{{ $withholdingTaxReceipt->number }}</p>
                    <p class="text-sm text-ink-faint">Date: {{ $withholdingTaxReceipt->receipt_date }}</p>
                </div>
            </div>

            <div class="mb-6">
                <h3 class="text-sm font-semibold text-ink-faint uppercase mb-2">Customer</h3>
                @if ($withholdingTaxReceipt->customer)
                    <p class="font-medium text-ink">{{ $withholdingTaxReceipt->customer->company_name }}</p>
                    @if ($withholdingTaxReceipt->customer->contact_name)
                        <p class="text-sm text-ink-soft">{{ $withholdingTaxReceipt->customer->contact_name }}</p>
                    @endif
                    @if ($withholdingTaxReceipt->customer->address)
                        <p class="text-sm text-ink-soft">{{ $withholdingTaxReceipt->customer->address }}</p>
                    @endif
                @else
                    <p class="text-sm text-ink-soft">—</p>
                @endif
            </div>

            @if ($withholdingTaxReceipt->invoice)
                <div class="mb-6">
                    <h3 class="text-sm font-semibold text-ink-faint uppercase mb-2">Related Invoice</h3>
                    <p class="text-sm text-ink">{{ $withholdingTaxReceipt->invoice->number }}</p>
                </div>
            @endif

            <div class="grid grid-cols-2 gap-x-6 gap-y-2 mb-6">
                <div>
                    <span class="text-sm text-ink-faint">Receipt date: </span>
                    <span class="text-sm text-ink">{{ $withholdingTaxReceipt->receipt_date }}</span>
                </div>
                <div>
                    <span class="text-sm text-ink-faint">Currency: </span>
                    <span class="text-sm text-ink">{{ $withholdingTaxReceipt->currency ?: settings('company.currency') ?: 'USD' }}</span>
                </div>
            </div>

            <table class="table-base mb-6">
                <thead>
                    <tr>
                        <th class="text-left">Description</th>
                        <th class="text-right">Amount</th>
                        <th class="text-right">Tax Rate</th>
                        <th class="text-right">Tax Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="text-sm text-ink">Withholding tax on payment</td>
                        <td class="text-right text-sm text-ink">{{ money($withholdingTaxReceipt->amount, $withholdingTaxReceipt->currency) }}</td>
                        <td class="text-right text-sm text-ink">{{ $withholdingTaxReceipt->tax_rate_percent }}%</td>
                        <td class="text-right text-sm font-medium text-ink">{{ money($withholdingTaxReceipt->tax_amount, $withholdingTaxReceipt->currency) }}</td>
                    </tr>
                </tbody>
            </table>

            @if ($withholdingTaxReceipt->notes)
                <div class="mb-6">
                    <h3 class="text-sm font-semibold text-ink-faint uppercase mb-2">Notes</h3>
                    <p class="text-sm text-ink">{{ $withholdingTaxReceipt->notes }}</p>
                </div>
            @endif

            <div class="border-t border-line pt-4 text-center text-xs text-ink-faint">
                <p>Thank you for your business.</p>
            </div>
        </div>
    </div>
</x-app-layout>
