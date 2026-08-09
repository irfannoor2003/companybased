<x-app-layout :pageTitle="'Transfer '.$transfer->number">
    <x-slot name="header">
        <x-page-header title="Transfer {{ $transfer->number }}" description="{{ $transfer->fromWarehouse?->name }} → {{ $transfer->toWarehouse?->name }}" icon="arrow-right">
            <x-slot name="actions">
                <x-button href="{{ route('inventory.transfers.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-card title="Transfer details">
                @if (in_array($transfer->status, ['completed', 'cancelled']))
                    <div class="mb-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-400">
                        This transfer is {{ $transfer->status }} and cannot be edited. Stock has already moved.
                    </div>
                @endif

                <form method="POST" action="{{ route('inventory.transfers.update', $transfer) }}" class="space-y-5">
                    @csrf
                    @method('PUT')

                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
                        <x-select name="from_warehouse_id" label="From warehouse" required :disabled="in_array($transfer->status, ['completed', 'cancelled'])">
                            @foreach ($warehouses as $warehouse)
                                <option value="{{ $warehouse->id }}" @selected(old('from_warehouse_id', $transfer->from_warehouse_id) == $warehouse->id)>{{ $warehouse->name }}</option>
                            @endforeach
                        </x-select>
                        <x-select name="to_warehouse_id" label="To warehouse" required :disabled="in_array($transfer->status, ['completed', 'cancelled'])">
                            @foreach ($warehouses as $warehouse)
                                <option value="{{ $warehouse->id }}" @selected(old('to_warehouse_id', $transfer->to_warehouse_id) == $warehouse->id)>{{ $warehouse->name }}</option>
                            @endforeach
                        </x-select>
                        <x-input name="transfer_date" label="Transfer date" type="date" value="{{ old('transfer_date', $transfer->transfer_date?->format('Y-m-d')) }}" :disabled="in_array($transfer->status, ['completed', 'cancelled'])" />
                        <x-select name="status" label="Status" :disabled="in_array($transfer->status, ['completed', 'cancelled'])">
                            @foreach (\App\Models\InventoryTransfer::statusOptions() as $status)
                                <option value="{{ $status }}" @selected(old('status', $transfer->status) === $status)>{{ ucfirst($status) }}</option>
                            @endforeach
                        </x-select>
                    </div>

                    @php
                        $initialItems = $transfer->items->map(fn ($item) => [
                            'item_id' => (string) ($item->item_id ?? ''),
                            'quantity' => (float) $item->quantity,
                            'notes' => $item->notes ?? '',
                        ])->all();
                        $locked = in_array($transfer->status, ['completed', 'cancelled']);
                    @endphp

                    <x-inventory.transfer-items-editor :items="$items" :initial-items="$initialItems" :locked="$locked" />

                    <x-textarea name="note" label="Note" rows="3" :disabled="$locked">{{ old('note', $transfer->note) }}</x-textarea>

                    <div class="flex justify-end gap-3 border-t border-line pt-4">
                        @if (! $locked)
                            <x-button type="submit" icon="save">Save changes</x-button>
                        @endif
                    </div>
                </form>
            </x-card>
        </div>

        <div class="space-y-6">
            <x-card title="Status">
                <div class="flex items-center justify-between">
                    <x-inventory.status-badge :status="$transfer->status" />
                    @if (auth()->user()->can('inventory.transfers.edit') && $transfer->status !== 'completed')
                        <form method="POST" action="{{ route('inventory.transfers.status', $transfer) }}" class="flex gap-2">
                            @csrf
                            @method('PATCH')
                            <x-select name="status" size="sm" class="w-32">
                                @foreach (\App\Models\InventoryTransfer::statusOptions() as $status)
                                    <option value="{{ $status }}" @selected($transfer->status === $status)>{{ ucfirst($status) }}</option>
                                @endforeach
                            </x-select>
                            <x-button type="submit" size="sm" variant="secondary">Update</x-button>
                        </form>
                    @endif
                </div>
                @if ($transfer->status === 'completed')
                    <p class="mt-3 text-xs text-ink-faint">Completing a transfer moves stock between warehouses in the ledger.</p>
                @endif
            </x-card>

            <x-card title="Summary">
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-ink-faint">Items</dt>
                        <dd class="font-medium text-ink">{{ $transfer->items->sum('quantity') }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-ink-faint">Lines</dt>
                        <dd class="font-medium text-ink">{{ $transfer->items->count() }}</dd>
                    </div>
                </dl>
            </x-card>
        </div>
    </div>
</x-app-layout>
