<x-app-layout :pageTitle="'Edit visit'">
    <x-slot name="header">
        <x-page-header :title="$visit->visit_number" description="Update visit details." icon="tag" />
    </x-slot>

    @include('visits._tabs')

    <div class="mt-6 max-w-3xl">
        <x-card>
            <form method="POST" action="{{ route('visits.update', $visit) }}" class="space-y-5">
                @csrf
                @method('PUT')

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-select name="customer_id" label="Customer" :error="$errors->first('customer_id')">
                        <option value="">No customer</option>
                        @foreach ($customers as $customer)
                            <option value="{{ $customer->id }}" @selected(old('customer_id', $visit->customer_id) == $customer->id)>{{ $customer->company_name }}</option>
                        @endforeach
                    </x-select>
                    <x-select name="sales_rep_id" label="Sales rep" :error="$errors->first('sales_rep_id')">
                        <option value="">Unassigned</option>
                        @foreach ($salesReps as $rep)
                            <option value="{{ $rep->id }}" @selected(old('sales_rep_id', $visit->sales_rep_id) == $rep->id)>{{ $rep->fullName() }}</option>
                        @endforeach
                    </x-select>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-input name="purpose" label="Purpose" required placeholder="e.g. Product demo, renew contract" value="{{ old('purpose', $visit->purpose) }}" :error="$errors->first('purpose')" />
                    <x-input name="scheduled_at" label="Scheduled date" type="date" required value="{{ old('scheduled_at', $visit->scheduled_at?->format('Y-m-d')) }}" :error="$errors->first('scheduled_at')" />
                </div>

                <div class="flex items-center gap-2 pt-1">
                    <x-button type="submit" icon="save">Save changes</x-button>
                    <x-button href="{{ route('visits.show', $visit) }}" variant="secondary">Cancel</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>