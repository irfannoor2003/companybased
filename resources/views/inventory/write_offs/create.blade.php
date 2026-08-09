<x-app-layout :pageTitle="'New write-off'">
    <x-slot name="header">
        <x-page-header title="New write-off" description="Remove damaged, expired or lost stock from a warehouse." icon="trash">
            <x-slot name="actions">
                <x-button href="{{ route('inventory.write_offs.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 max-w-6xl">
        <x-card title="Write-off details">
            <form method="POST" action="{{ route('inventory.write_offs.store') }}" class="space-y-5">
                @csrf

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
                    <x-select name="warehouse_id" label="Warehouse" required>
                        <option value="">— Select —</option>
                        @foreach ($warehouses as $warehouse)
                            <option value="{{ $warehouse->id }}" @selected(old('warehouse_id') == $warehouse->id)>{{ $warehouse->name }}</option>
                        @endforeach
                    </x-select>
                    <x-input name="write_off_date" label="Write-off date" type="date" value="{{ old('write_off_date', now()->toDateString()) }}" />
                    <x-input name="reason" label="Reason" value="{{ old('reason') }}" placeholder="e.g. Damaged in transit" />
                    <x-select name="status" label="Status">
                        @foreach (\App\Models\InventoryWriteOff::statusOptions() as $status)
                            <option value="{{ $status }}" @selected(old('status', 'draft') === $status)>{{ ucfirst($status) }}</option>
                        @endforeach
                    </x-select>
                </div>

                <x-inventory.writeoff-items-editor :items="$items" />

                <x-textarea name="note" label="Note" rows="3">{{ old('note') }}</x-textarea>

                <div class="flex justify-end gap-3 border-t border-line pt-4">
                    <x-button href="{{ route('inventory.write_offs.index') }}" variant="ghost">Cancel</x-button>
                    <x-button type="submit" icon="save">Create write-off</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
