<x-app-layout :pageTitle="'Receipt '.$sale->receipt_number">
    <x-slot name="header">
        <x-page-header :title="'Receipt '.$sale->receipt_number" description="{{ $sale->sold_at?->format('Y-m-d H:i') }}" icon="document">
            <x-slot name="actions">
                <x-button href="{{ route('pos.receipts.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6">
        <x-card>
            <div class="mx-auto max-w-lg">
                <div class="text-center">
                    <h3 class="text-sm font-semibold text-ink">{{ config('app.name') }}</h3>
                    <p class="mt-1 font-mono text-sm text-ink-soft">{{ $sale->receipt_number }}</p>
                    <p class="mt-0.5 text-xs text-ink-faint">Sold {{ $sale->sold_at?->format('Y-m-d H:i:s') }} · Shift {{ $sale->shift?->shift_number }} · {{ $sale->shift?->opener?->name }}</p>
                    @if ($sale->customer_name)
                        <p class="mt-0.5 text-xs text-ink-faint">Customer: {{ $sale->customer_name }}</p>
                    @endif
                </div>

                <div class="mt-5 border-t border-line pt-4">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-xs uppercase tracking-wide text-ink-faint">
                                <th class="py-1 text-left font-medium">Item</th>
                                <th class="py-1 text-center font-medium">Qty</th>
                                <th class="py-1 text-right font-medium">Price</th>
                                <th class="py-1 text-right font-medium">Total</th>
                            </tr>
                        </thead>
                        <tbody class="text-ink-soft">
                            @foreach ($sale->items as $item)
                                <tr class="border-t border-line">
                                    <td class="py-2">{{ $item->item_name }}</td>
                                    <td class="py-2 text-center">{{ number_format((float) $item->quantity, 2) }}</td>
                                    <td class="py-2 text-right">{{ money($item->unit_price) }}</td>
                                    <td class="py-2 text-right">{{ money($item->line_total) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 space-y-1.5 border-t border-line pt-4 text-sm">
                    <div class="flex justify-between text-ink-soft">
                        <span>Subtotal</span>
                        <span>{{ money($sale->subtotal) }}</span>
                    </div>
                    @if ((float) $sale->discount > 0)
                        <div class="flex justify-between text-ink-soft">
                            <span>Discount</span>
                            <span>-{{ money($sale->discount) }}</span>
                        </div>
                    @endif
                    @if ((float) $sale->tax > 0)
                        <div class="flex justify-between text-ink-soft">
                            <span>Tax</span>
                            <span>{{ money($sale->tax) }}</span>
                        </div>
                    @endif
                    <div class="flex justify-between border-t border-line pt-2 font-semibold text-ink">
                        <span>Total</span>
                        <span>{{ money($sale->total) }}</span>
                    </div>
                    <div class="flex justify-between text-ink-soft">
                        <span>Paid via {{ $sale->paymentMethod?->name ?: '—' }}</span>
                        <span>{{ money($sale->amount_paid) }}</span>
                    </div>
                    @if ((float) $sale->change_due > 0)
                        <div class="flex justify-between text-ink-soft">
                            <span>Change</span>
                            <span>{{ money($sale->change_due) }}</span>
                        </div>
                    @endif
                </div>

                @if ($sale->notes)
                    <p class="mt-4 text-xs text-ink-faint">{{ $sale->notes }}</p>
                @endif
            </div>
        </x-card>
    </div>
</x-app-layout>