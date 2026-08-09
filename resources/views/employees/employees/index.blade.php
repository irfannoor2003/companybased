<x-app-layout :pageTitle="'Employees'">
    <x-slot name="header">
        <x-page-header title="Employees" description="Manage the people behind the business." icon="employees">
            <x-slot name="actions">
                @if (auth()->user()->can('employees.employees.export'))
                    <x-export route="employees.employees.export" />
                @endif
                @if (auth()->user()->can('employees.employees.create'))
                    <x-button href="{{ route('employees.employees.create') }}" icon="plus">New employee</x-button>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    @include('employees._tabs')

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-stat-card label="Active employees" :value="$totalActive" icon="employees" tone="primary" />
        <x-stat-card label="On leave" :value="$onLeave" icon="clock" tone="warning" />
        <x-stat-card label="Attendance enabled" :value="$attendanceEnabled" icon="check" tone="info" />
    </div>

    <div class="mt-6">
        <x-card :padding="false">
            <form method="GET" action="{{ route('employees.employees.index') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
                <div class="min-w-[220px] flex-1">
                    <x-input name="search" label="Search" placeholder="Name, code, email…" leadingIcon="search"
                        value="{{ request('search') }}" size="sm" />
                </div>
                <div class="w-48">
                    <x-select name="department_id" label="Department" size="sm">
                        <option value="">Any department</option>
                        @foreach ($departments as $department)
                            <option value="{{ $department->id }}" @selected(request('department_id') == $department->id)>{{ $department->name }}</option>
                        @endforeach
                    </x-select>
                </div>
                <div class="w-40">
                    <x-select name="status" label="Status" size="sm">
                        <option value="">Any status</option>
                        @foreach (\App\Models\Employee::employmentStatusOptions() as $status)
                            <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
                        @endforeach
                    </x-select>
                </div>
                <div class="flex gap-2">
                    <x-button type="submit" size="sm" icon="filter">Filter</x-button>
                    @if (request()->hasAny(['search', 'department_id', 'status']))
                        <x-button href="{{ route('employees.employees.index') }}" variant="ghost" size="sm">Clear</x-button>
                    @endif
                </div>
            </form>

            @if ($employees->isEmpty())
                <x-empty-state icon="employees" title="No employees" description="Add your first employee to get started." />
            @else
                <div class="table-wrap !border-0 !rounded-none">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Code</th>
                                <th>Department</th>
                                <th>Job title</th>
                                <th>Status</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($employees as $employee)
                                <tr>
                                    <td>
                                        <a href="{{ route('employees.employees.show', $employee) }}" class="font-medium text-ink hover:text-primary">
                                            {{ $employee->fullName() }}
                                        </a>
                                        @if ($employee->email)
                                            <span class="block text-xs text-ink-faint">{{ $employee->email }}</span>
                                        @endif
                                    </td>
                                    <td class="font-mono text-ink-soft">{{ $employee->employee_code }}</td>
                                    <td class="text-ink-soft">{{ $employee->department?->name ?: '—' }}</td>
                                    <td class="text-ink-soft">{{ $employee->job_title ?: '—' }}</td>
                                    <td><x-employees.status-badge :status="$employee->employment_status" /></td>
                                    <td class="text-right">
                                        <div class="inline-flex gap-1">
                                            <a href="{{ route('employees.employees.show', $employee) }}" class="btn-ghost btn-icon btn-sm" title="View">
                                                <x-icon name="eye" class="size-4" />
                                            </a>
                                            @if (auth()->user()->can('employees.employees.edit'))
                                                <a href="{{ route('employees.employees.edit', $employee) }}" class="btn-ghost btn-icon btn-sm" title="Edit">
                                                    <x-icon name="tag" class="size-4" />
                                                </a>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($employees->hasPages())
                <div class="px-5 py-4">
                    {{ $employees->links() }}
                </div>
            @endif
        </x-card>
    </div>
</x-app-layout>
