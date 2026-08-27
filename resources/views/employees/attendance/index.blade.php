<x-app-layout :pageTitle="'Attendance'">
    <x-slot name="header">
        <x-page-header title="Attendance" description="Daily check-ins and check-outs with automatic rule evaluation." icon="clock">
            <x-slot name="actions">
                @if (auth()->user()->can('employees.attendance.view'))
                    <x-button href="{{ route('employees.attendance.qr-code') }}" variant="secondary" icon="scan">Office QR Code</x-button>
                @endif
                @if (auth()->user()->can('employees.attendance.export'))
                    <x-export route="employees.attendance.export" />
                @endif
                @if (auth()->user()->can('employees.attendance.edit'))
                    <x-button href="{{ route('employees.attendance.rules') }}" variant="secondary" icon="settings">Rules</x-button>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    @include('employees._tabs')

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-4">
        <x-stat-card label="Present" :value="$present" icon="check" tone="success" />
        <x-stat-card label="Late" :value="$late" icon="clock" tone="warning" />
        <x-stat-card label="Absent" :value="$absent" icon="x" tone="danger" />
        <x-stat-card label="Attendance enabled" :value="$totalEnabled" icon="users" tone="info" />
    </div>

    <div class="mt-6">
        <x-card :padding="false">
            <div class="border-b border-line px-5 py-4">
                <form method="GET" action="{{ route('employees.attendance.index') }}" class="flex flex-wrap items-end gap-3">
                    <div class="w-44">
                        <x-input name="date" label="Date" type="date" value="{{ request('date', $date) }}" size="sm" />
                    </div>
                    <div class="w-48">
                        <x-select name="employee_id" label="Employee" size="sm">
                            <option value="">All employees</option>
                            @foreach ($employees as $employee)
                                <option value="{{ $employee->id }}" @selected(request('employee_id') == $employee->id)>{{ $employee->fullName() }} ({{ $employee->employee_code }})</option>
                            @endforeach
                        </x-select>
                    </div>
                    <div class="w-40">
                        <x-select name="status" label="Status" size="sm">
                            <option value="">Any status</option>
                            @foreach (\App\Models\AttendanceRecord::statusOptions() as $status)
                                <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
                            @endforeach
                        </x-select>
                    </div>
                    <div class="flex gap-2">
                        <x-button type="submit" size="sm" icon="filter">Filter</x-button>
                        @if (request()->hasAny(['date', 'employee_id', 'status']))
                            <x-button href="{{ route('employees.attendance.index') }}" variant="ghost" size="sm">Today</x-button>
                        @endif
                    </div>
                </form>
            </div>

            @if (auth()->user()->can('employees.attendance.mark'))
                <div class="border-b border-line px-5 py-4">
                    <form method="POST" action="{{ route('employees.attendance.mark') }}" class="flex flex-wrap items-end gap-3">
                        @csrf
                        <div class="w-64">
                            <x-select name="employee_id" label="Manual clock-in/out" size="sm">
                                @foreach ($employees as $employee)
                                    <option value="{{ $employee->id }}">{{ $employee->fullName() }} ({{ $employee->employee_code }})</option>
                                @endforeach
                            </x-select>
                        </div>
                        <x-button type="submit" size="sm" icon="clock">Mark now</x-button>
                    </form>
                </div>
            @endif

            @if ($records->isEmpty())
                <x-empty-state icon="clock" title="No attendance records" description="No records match the selected date and filters." />
            @else
                <div class="table-wrap !border-0 !rounded-none">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Department</th>
                                <th>In</th>
                                <th>Out</th>
                                <th class="text-right">Work</th>
                                <th>Method</th>
                                <th>Status</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($records as $record)
                                <tr x-data="{ editing: false }">
                                    <td class="font-medium text-ink">{{ $record->employee?->fullName() ?: '—' }}</td>
                                    <td class="text-ink-soft">{{ $record->employee?->department?->name ?: '—' }}</td>
                                    <td class="text-ink-soft">{{ $record->check_in_at?->format('H:i') ?: '—' }}</td>
                                    <td class="text-ink-soft">{{ $record->check_out_at?->format('H:i') ?: '—' }}</td>
                                    <td class="text-right text-ink-soft">{{ $record->work_minutes ? gmdate('H:i', $record->work_minutes * 60) : '—' }}</td>
                                    <td>
                                        @if ($record->method)
                                            <x-employees.status-badge :status="$record->method" />
                                        @else
                                            <span class="text-ink-faint">—</span>
                                        @endif
                                    </td>
                                    <td><x-employees.status-badge :status="$record->status ?? 'pending'" /></td>
                                    <td class="text-right">
                                        <div class="inline-flex gap-1">
                                            @if (auth()->user()->can('employees.attendance.edit'))
                                                <button type="button" class="btn-ghost btn-icon btn-sm" title="Edit" @click="editing = !editing">
                                                    <x-icon name="tag" class="size-4" />
                                                </button>
                                            @endif
                                            @if (auth()->user()->can('employees.attendance.delete'))
                                                <form method="POST" action="{{ route('employees.attendance.destroy', $record) }}" onsubmit="return confirm('Delete this attendance record?')">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn-ghost btn-icon btn-sm text-rose-500" title="Delete">
                                                        <x-icon name="trash" class="size-4" />
                                                    </button>
                                                </form>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                                @if (auth()->user()->can('employees.attendance.edit'))
                                    <tr x-show="editing" x-cloak class="bg-surface-muted/40">
                                        <td colspan="8" class="!p-4">
                                            <form method="POST" action="{{ route('employees.attendance.update', $record) }}" class="flex flex-wrap items-end gap-3">
                                                @csrf
                                                @method('PATCH')
                                                <div class="w-44">
                                                    <x-input name="check_in_at" label="Check in" type="datetime-local" value="{{ $record->check_in_at?->format('Y-m-d\TH:i') }}" size="sm" />
                                                </div>
                                                <div class="w-44">
                                                    <x-input name="check_out_at" label="Check out" type="datetime-local" value="{{ $record->check_out_at?->format('Y-m-d\TH:i') }}" size="sm" />
                                                </div>
                                                <div class="w-40">
                                                    <x-select name="status" label="Status" size="sm">
                                                        @foreach (\App\Models\AttendanceRecord::statusOptions() as $status)
                                                            <option value="{{ $status }}" @selected(($record->status ?? 'pending') === $status)>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
                                                        @endforeach
                                                    </x-select>
                                                </div>
                                                <div class="min-w-[200px] flex-1">
                                                    <x-input name="notes" label="Notes" placeholder="Optional note" value="{{ $record->notes }}" size="sm" />
                                                </div>
                                                <div class="flex gap-2">
                                                    <x-button type="submit" size="sm" icon="save">Save</x-button>
                                                    <x-button type="button" variant="ghost" size="sm" @click="editing = false">Cancel</x-button>
                                                </div>
                                            </form>
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($records->hasPages())
                <div class="px-5 py-4">
                    {{ $records->links() }}
                </div>
            @endif
        </x-card>
    </div>
</x-app-layout>