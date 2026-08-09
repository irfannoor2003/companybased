<x-app-layout :pageTitle="'Edit salary structure'">
    <x-slot name="header">
        <x-page-header title="Edit salary structure" :description="$structure->employee?->fullName()" icon="tag" />
    </x-slot>

    @include('employees._tabs')

    <div class="mt-6 max-w-3xl">
        <x-card>
            <form method="POST" action="{{ route('employees.salary_structures.update', $structure) }}" class="space-y-5">
                @csrf
                @method('PUT')

                <x-select name="employee_id" label="Employee" required :error="$errors->first('employee_id')">
                    @foreach ($employees as $employee)
                        <option value="{{ $employee->id }}" @selected(old('employee_id', $structure->employee_id) == $employee->id)>{{ $employee->fullName() }}</option>
                    @endforeach
                </x-select>

                <x-input name="effective_from" label="Effective from" type="date" required value="{{ old('effective_from', $structure->effective_from?->format('Y-m-d')) }}" :error="$errors->first('effective_from')" />

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-input name="basic_salary" label="Basic salary" type="number" step="0.01" min="0" required placeholder="0.00" value="{{ old('basic_salary', $structure->basic_salary) }}" :error="$errors->first('basic_salary')" />
                    <x-input name="housing_allowance" label="Housing allowance" type="number" step="0.01" min="0" placeholder="0.00" value="{{ old('housing_allowance', $structure->housing_allowance) }}" :error="$errors->first('housing_allowance')" />
                    <x-input name="transport_allowance" label="Transport allowance" type="number" step="0.01" min="0" placeholder="0.00" value="{{ old('transport_allowance', $structure->transport_allowance) }}" :error="$errors->first('transport_allowance')" />
                    <x-input name="other_allowance" label="Other allowance" type="number" step="0.01" min="0" placeholder="0.00" value="{{ old('other_allowance', $structure->other_allowance) }}" :error="$errors->first('other_allowance')" />
                </div>

                <x-textarea name="notes" label="Notes">{{ old('notes', $structure->notes) }}</x-textarea>

                <div class="flex items-center gap-2 pt-1">
                    <x-button type="submit" icon="save">Save changes</x-button>
                    <x-button href="{{ route('employees.salary_structures.index') }}" variant="secondary">Cancel</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>