<x-app-layout :pageTitle="'New transfer'">
    <x-slot name="header">
        <x-page-header title="New transfer" description="Move stock between two warehouses." icon="arrow-right">
            <x-slot name="actions">
                <x-button href="{{ route('inventory.transfers.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 max-w-6xl">
        <x-card title="Transfer details">
            <form method="POST" action="{{ route('inventory.transfers.store') }}" class="space-y-5">
                @csrf

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
                    <x-select name="from_warehouse_id" label="From warehouse" required>
                        <option value="">— Select —</option>
                        @foreach ($warehouses as $warehouse)
                            <option value="{{ $warehouse->id }}" @selected(old('from_warehouse_id') == $warehouse->id)>{{ $warehouse->name }}</option>
                        @endforeach
                    </x-select>
                    <x-select name="to_warehouse_id" label="To warehouse" required>
                        <option value="">— Select —</option>
                        @foreach ($warehouses as $warehouse)
                            <option value="{{ $warehouse->id }}" @selected(old('to_warehouse_id') == $warehouse->id)>{{ $warehouse->name }}</option>
                        @endforeach
                    </x-select>
                    <x-input name="transfer_date" label="Transfer date" type="date" value="{{ old('transfer_date', now()->toDateString()) }}" />
                    <x-select name="status" label="Status">
                        @foreach (\App\Models\InventoryTransfer::statusOptions() as $status)
                            <option value="{{ $status }}" @selected(old('status', 'draft') === $status)>{{ ucfirst($status) }}</option>
                        @endforeach
                    </x-select>
                </div>

                <x-inventory.transfer-items-editor :items="$items" />

                <x-textarea name="note" label="Note" rows="3">{{ old('note') }}</x-textarea>

                <div class="flex justify-end gap-3 border-t border-line pt-4">
                    <x-button href="{{ route('inventory.transfers.index') }}" variant="ghost">Cancel</x-button>
                    <x-button type="submit" icon="save">Create transfer</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
