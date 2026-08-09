<x-app-layout :pageTitle="'New employee'">
    <x-slot name="header">
        <x-page-header title="New employee" description="Add an employee profile and optionally link it to a user account." icon="employees" />
    </x-slot>

    @include('employees._tabs')

    <div class="mt-6 max-w-4xl">
        <x-card>
            <form method="POST" action="{{ route('employees.employees.store') }}" class="space-y-6">
                @csrf

                <div>
                    <h3 class="text-sm font-semibold text-ink">Profile</h3>
                    <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-input name="first_name" label="First name" required value="{{ old('first_name') }}" :error="$errors->first('first_name')" />
                        <x-input name="last_name" label="Last name" required value="{{ old('last_name') }}" :error="$errors->first('last_name')" />
                        <x-input name="employee_code" label="Employee code" required placeholder="e.g. EMP-001" value="{{ old('employee_code') }}" :error="$errors->first('employee_code')" />
                        <x-input name="email" label="Email" type="email" placeholder="name@company.com" value="{{ old('email') }}" :error="$errors->first('email')" />
                        <x-input name="phone" label="Phone" placeholder="+1 555 000 0000" value="{{ old('phone') }}" :error="$errors->first('phone')" />
                    </div>
                </div>

                <div>
                    <h3 class="text-sm font-semibold text-ink">Employment</h3>
                    <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-select name="department_id" label="Department" :error="$errors->first('department_id')">
                            <option value="">No department</option>
                            @foreach ($departments as $department)
                                <option value="{{ $department->id }}" @selected(old('department_id') == $department->id)>{{ $department->name }}</option>
                            @endforeach
                        </x-select>
                        <x-input name="job_title" label="Job title" placeholder="e.g. Software Engineer" value="{{ old('job_title') }}" :error="$errors->first('job_title')" />
                        <x-select name="employment_status" label="Employment status" required :error="$errors->first('employment_status')">
                            @foreach (\App\Models\Employee::employmentStatusOptions() as $status)
                                <option value="{{ $status }}" @selected(old('employment_status', 'active') === $status)>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
                            @endforeach
                        </x-select>
                        <x-input name="date_hired" label="Date hired" type="date" required value="{{ old('date_hired') }}" :error="$errors->first('date_hired')" />
                        <x-select name="user_id" label="Linked user" :error="$errors->first('user_id')">
                            <option value="">Not linked</option>
                            @foreach ($users as $user)
                                <option value="{{ $user->id }}" @selected(old('user_id') == $user->id)>{{ $user->name }} ({{ $user->email }})</option>
                            @endforeach
                        </x-select>
                        <x-input name="date_of_birth" label="Date of birth" type="date" value="{{ old('date_of_birth') }}" :error="$errors->first('date_of_birth')" />
                    </div>
                </div>

                <div>
                    <x-textarea name="address" label="Address" :error="$errors->first('address')">{{ old('address') }}</x-textarea>
                </div>

                <x-toggle name="attendance_enabled" label="Attendance tracking" description="Allow this employee to record attendance via QR, fingerprint or manual clock." :checked="old('attendance_enabled', true)" />

                <div class="flex items-center gap-2 pt-1">
                    <x-button type="submit" icon="save">Create employee</x-button>
                    <x-button href="{{ route('employees.employees.index') }}" variant="secondary">Cancel</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>