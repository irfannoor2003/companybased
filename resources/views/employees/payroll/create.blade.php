<x-app-layout :pageTitle="'New payroll run'">
    <x-slot name="header">
        <x-page-header title="New payroll run" description="Create a run for a pay period and generate payslips." icon="plus" />
    </x-slot>

    @include('employees._tabs')

    <div class="mt-6 max-w-3xl">
        <x-card>
            <form method="POST" action="{{ route('employees.payroll.store') }}" class="space-y-5">
                @csrf

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-input name="period_start" label="Period start" type="date" required value="{{ old('period_start', now()->startOfMonth()->format('Y-m-d')) }}" :error="$errors->first('period_start')" />
                    <x-input name="period_end" label="Period end" type="date" required value="{{ old('period_end', now()->endOfMonth()->format('Y-m-d')) }}" :error="$errors->first('period_end')" />
                </div>

                <x-input name="currency" label="Currency" required value="{{ old('currency', settings('company.currency', 'USD')) }}" :error="$errors->first('currency')" />

                <div>
                    <label class="label" for="employee_ids">Include employees</label>
                    <select name="employee_ids[]" id="employee_ids" multiple size="6" class="select-input">
                        <option value="" disabled>Leave empty to include all employees</option>
                        @foreach ($employees as $employee)
                            <option value="{{ $employee->id }}" @selected(is_array(old('employee_ids')) && in_array($employee->id, old('employee_ids')))>
                                {{ $employee->fullName() }} ({{ $employee->employee_code }})
                            </option>
                        @endforeach
                    </select>
                    @if ($errors->first('employee_ids'))
                        <p class="mt-1.5 text-xs text-rose-600 dark:text-rose-400">{{ $errors->first('employee_ids') }}</p>
                    @endif
                </div>

                <x-textarea name="notes" label="Notes">{{ old('notes') }}</x-textarea>

                <div class="flex items-center gap-2 pt-1">
                    <x-button type="submit" icon="save">Generate payroll</x-button>
                    <x-button href="{{ route('employees.payroll.index') }}" variant="secondary">Cancel</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>