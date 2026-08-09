<x-app-layout :pageTitle="'Record till count'">
    <x-slot name="header">
        <x-page-header title="Record till count" description="Reconcile counted cash against a closed shift." icon="report" />
    </x-slot>

    @include('pos._tabs')

    <div class="mt-6 max-w-3xl">
        <x-card>
            <form method="POST" action="{{ route('pos.reconciliations.store') }}" class="space-y-5">
                @csrf

                <x-select name="shift_id" label="Closed shift" required :error="$errors->first('shift_id')">
                    <option value="">Select a closed shift…</option>
                    @foreach ($shifts as $shift)
                        <option value="{{ $shift->id }}" @selected(old('shift_id') == $shift->id)>
                            {{ $shift->shift_number }} · {{ $shift->opened_at?->format('Y-m-d H:i') }} · {{ money($shift->salesTotal()) }} sales
                        </option>
                    @endforeach
                </x-select>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-input name="opening_cash" label="Opening cash" type="number" step="0.01" min="0" required placeholder="0.00" value="{{ old('opening_cash') }}" :error="$errors->first('opening_cash')" />
                    <x-input name="counted_cash" label="Counted cash" type="number" step="0.01" min="0" required placeholder="0.00" value="{{ old('counted_cash') }}" :error="$errors->first('counted_cash')" />
                </div>

                <x-textarea name="notes" label="Notes">{{ old('notes') }}</x-textarea>

                <div class="flex items-center gap-2 pt-1">
                    <x-button type="submit" icon="save">Record count</x-button>
                    <x-button href="{{ route('pos.reconciliations.index') }}" variant="secondary">Cancel</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>