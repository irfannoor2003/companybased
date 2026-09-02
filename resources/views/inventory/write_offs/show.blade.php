<x-app-layout :pageTitle="'Write-off '.$writeOff->number">
    <x-slot name="header">
        <x-page-header title="Write-off {{ $writeOff->number }}" description="{{ $writeOff->warehouse?->name }}" icon="trash">
            <x-slot name="actions">
                @if (auth()->user()->can('inventory.write_offs.edit') && ! in_array($writeOff->status, ['completed', 'cancelled']))
                    <x-button href="{{ route('inventory.write_offs.edit', $writeOff) }}" variant="secondary" icon="edit">Edit</x-button>
                @endif
                <x-button href="{{ route('inventory.write_offs.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-card :padding="false">
                <div class="border-b border-line px-5 py-4">
                    <h2 class="text-sm font-semibold text-ink">Items written off</h2>
                </div>

                @if ($writeOff->items->isEmpty())
                    <x-empty-state icon="trash" title="No items" description="No items were recorded on this write-off." />
                @else
                    <div class="table-wrap !border-0 !rounded-none">
                        <table class="table-base">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th>SKU</th>
                                    <th class="text-right">Qty</th>
                                    <th>Reason</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($writeOff->items as $line)
                                    <tr>
                                        <td class="font-medium text-ink">{{ $line->item?->product?->name ?? '—' }}</td>
                                        <td class="text-ink-soft">{{ $line->item?->product?->sku ?? '—' }}</td>
                                        <td class="text-right text-ink">{{ $line->quantity }}</td>
                                        <td class="text-ink-soft">{{ $line->reason ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-card>
        </div>

        <div class="space-y-6">
            <x-card title="Details">
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-ink-faint">Warehouse</dt>
                        <dd class="font-medium text-ink">{{ $writeOff->warehouse?->name ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-ink-faint">Date</dt>
                        <dd class="font-medium text-ink">{{ $writeOff->write_off_date?->format('Y-m-d') ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-ink-faint">Reason</dt>
                        <dd class="font-medium text-ink">{{ $writeOff->reason ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-ink-faint">Status</dt>
                        <dd><x-inventory.status-badge :status="$writeOff->status" /></dd>
                    </div>
                </dl>
            </x-card>

            @if ($writeOff->note)
                <x-card title="Note">
                    <p class="text-sm text-ink-soft">{{ $writeOff->note }}</p>
                </x-card>
            @endif

            <x-card title="Summary">
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-ink-faint">Quantity</dt>
                        <dd class="font-medium text-ink">{{ $writeOff->items->sum('quantity') }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-ink-faint">Lines</dt>
                        <dd class="font-medium text-ink">{{ $writeOff->items->count() }}</dd>
                    </div>
                </dl>
            </x-card>
        </div>
    </div>
</x-app-layout>
