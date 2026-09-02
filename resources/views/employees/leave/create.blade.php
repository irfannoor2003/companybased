<x-app-layout :pageTitle="'Request Leave'">
    <x-slot name="header">
        <x-page-header title="Request Leave" description="Apply for time off. Requests go to HR for approval." icon="calendar" />
    </x-slot>

    <div class="mt-6 max-w-2xl">
        <x-card title="Leave request" description="Applies to {{ $employee->fullName() }}.">
            <form method="POST" action="{{ route('employees.leave.my.store') }}" class="space-y-5">
                @csrf

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-select name="leave_type" label="Leave type" required :error="$errors->first('leave_type')">
                        @foreach (\App\Models\LeaveRequest::typeOptions() as $type)
                            <option value="{{ $type }}" @selected(old('leave_type') === $type)>{{ ucfirst($type) }}</option>
                        @endforeach
                    </x-select>
                    <div class="flex items-end pb-1">
                        <p class="text-sm text-ink-soft">Start date cannot be in the past. One request can cover up to 366 days.</p>
                    </div>
                    <x-input name="start_date" label="Start date" type="date" required value="{{ old('start_date') }}" :error="$errors->first('start_date')" />
                    <x-input name="end_date" label="End date" type="date" required value="{{ old('end_date') }}" :error="$errors->first('end_date')" />
                </div>

                <x-textarea name="reason" label="Reason" required placeholder="e.g. Annual vacation, medical appointment…">{{ old('reason') }}</x-textarea>

                <div class="flex items-center gap-2 pt-1">
                    <x-button type="submit" icon="save">Submit request</x-button>
                    <x-button href="{{ route('employees.leave.my') }}" variant="secondary">Cancel</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>