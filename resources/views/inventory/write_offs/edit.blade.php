<x-app-layout :pageTitle="'Write-off '.$writeOff->number">
    <x-slot name="header">
        <x-page-header title="Write-off {{ $writeOff->number }}" description="{{ $writeOff->warehouse?->name }}" icon="trash">
            <x-slot name="actions">
                <x-button href="{{ route('inventory.write_offs.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-card title="Write-off details">
                @if (in_array($writeOff->status, ['completed', 'cancelled']))
                    <div class="mb-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-400">
                        This write-off is {{ $writeOff->status }} and cannot be edited. Stock has already been reduced.
                    </div>
                @endif

                <form method="POST" action="{{ route('inventory.write_offs.update', $writeOff) }}" class="space-y-5">
                    @csrf
                    @method('PUT')

                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
                        <x-select name="warehouse_id" label="Warehouse" required :disabled="in_array($writeOff->status, ['completed', 'cancelled'])">
                            @foreach ($warehouses as $warehouse)
                                <option value="{{ $warehouse->id }}" @selected(old('warehouse_id', $writeOff->warehouse_id) == $warehouse->id)>{{ $warehouse->name }}</option>
                            @endforeach
                        </x-select>
                        <x-input name="write_off_date" label="Write-off date" type="date" value="{{ old('write_off_date', $writeOff->write_off_date?->format('Y-m-d')) }}" :disabled="in_array($writeOff->status, ['completed', 'cancelled'])" />
                        <x-input name="reason" label="Reason" value="{{ old('reason', $writeOff->reason) }}" :disabled="in_array($writeOff->status, ['completed', 'cancelled'])" />
                        <x-select name="status" label="Status" :disabled="in_array($writeOff->status, ['completed', 'cancelled'])">
                            @foreach (\App\Models\InventoryWriteOff::statusOptions() as $status)
                                <option value="{{ $status }}" @selected(old('status', $writeOff->status) === $status)>{{ ucfirst($status) }}</option>
                            @endforeach
                        </x-select>
                    </div>

                    @php
                        $initialItems = $writeOff->items->map(fn ($item) => [
                            'item_id' => (string) ($item->item_id ?? ''),
                            'quantity' => (float) $item->quantity,
                            'reason' => $item->reason ?? '',
                        ])->all();
                        $locked = in_array($writeOff->status, ['completed', 'cancelled']);
                    @endphp

                    <x-inventory.writeoff-items-editor :items="$items" :initial-items="$initialItems" :locked="$locked" />

                    <x-textarea name="note" label="Note" rows="3" :disabled="$locked">{{ old('note', $writeOff->note) }}</x-textarea>

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
                    <x-inventory.status-badge :status="$writeOff->status" />
                    @if (auth()->user()->can('inventory.write_offs.edit') && $writeOff->status !== 'completed')
                        <form method="POST" action="{{ route('inventory.write_offs.status', $writeOff) }}" class="flex gap-2">
                            @csrf
                            @method('PATCH')
                            <x-select name="status" size="sm" class="w-32">
                                @foreach (\App\Models\InventoryWriteOff::statusOptions() as $status)
                                    <option value="{{ $status }}" @selected($writeOff->status === $status)>{{ ucfirst($status) }}</option>
                                @endforeach
                            </x-select>
                            <x-button type="submit" size="sm" variant="secondary">Update</x-button>
                        </form>
                    @endif
                </div>
                @if ($writeOff->status === 'completed')
                    <p class="mt-3 text-xs text-ink-faint">Completing a write-off reduces stock in the ledger.</p>
                @endif
            </x-card>

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
