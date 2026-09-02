<x-app-layout :pageTitle="'Leave Requests'">
    <x-slot name="header">
        <x-page-header title="Leave Requests" description="Review and approve employee leave requests." icon="calendar">
            <x-slot name="actions">
                @if (auth()->user()->can('employees.leave_requests.export'))
                    <x-export route="employees.leave.export" />
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    @include('employees._tabs')

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-4">
        <x-stat-card label="Pending" :value="$pending" icon="clock" tone="warning" />
        <x-stat-card label="Approved" :value="$approved" icon="check" tone="success" />
        <x-stat-card label="Rejected" :value="$rejected" icon="x" tone="danger" />
        <x-stat-card label="Approved days this month" :value="$thisMonthDays" icon="calendar" tone="primary" />
    </div>

    <div class="mt-6">
        <x-card :padding="false">
            <div class="border-b border-line px-5 py-4">
                <form method="GET" action="{{ route('employees.leave.index') }}" class="flex flex-wrap items-end gap-3">
                    <div class="min-w-[220px] flex-1">
                        <x-input name="search" label="Search" placeholder="Employee or leave type…" leadingIcon="search"
                            value="{{ request('search') }}" size="sm" />
                    </div>
                    <div class="w-48">
                        <x-select name="employee_id" label="Employee" size="sm">
                            <option value="">All employees</option>
                            @foreach ($employees as $employee)
                                <option value="{{ $employee->id }}" @selected(request('employee_id') == $employee->id)>{{ $employee->fullName() }}</option>
                            @endforeach
                        </x-select>
                    </div>
                    <div class="w-40">
                        <x-select name="status" label="Status" size="sm">
                            <option value="">Any status</option>
                            @foreach (\App\Models\LeaveRequest::statusOptions() as $status)
                                <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
                            @endforeach
                        </x-select>
                    </div>
                    <div class="w-40">
                        <x-input name="from" label="From" type="date" value="{{ request('from') }}" size="sm" />
                    </div>
                    <div class="w-40">
                        <x-input name="to" label="To" type="date" value="{{ request('to') }}" size="sm" />
                    </div>
                    <div class="flex gap-2">
                        <x-button type="submit" size="sm" icon="filter">Filter</x-button>
                        @if (request()->hasAny(['search', 'employee_id', 'status', 'from', 'to']))
                            <x-button href="{{ route('employees.leave.index') }}" variant="ghost" size="sm">Clear</x-button>
                        @endif
                    </div>
                </form>
            </div>

            @if ($requests->isEmpty())
                <x-empty-state icon="calendar" title="No leave requests" description="No requests match the selected filters." />
            @else
                <div class="table-wrap !border-0 !rounded-none">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Type</th>
                                <th>Dates</th>
                                <th class="text-right">Days</th>
                                <th>Status</th>
                                <th>Requested</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($requests as $leave)
                                <tr>
                                    <td class="font-medium text-ink">{{ $leave->employee?->fullName() ?: '—' }}</td>
                                    <td>{{ ucfirst($leave->leave_type) }}</td>
                                    <td class="text-ink-soft">{{ $leave->start_date?->format('M d, Y') }} → {{ $leave->end_date?->format('M d, Y') }}</td>
                                    <td class="text-right text-ink-soft">{{ $leave->days }}</td>
                                    <td>
                                        <x-badge :color="match ($leave->status) { 'approved' => 'success', 'rejected' => 'danger', 'cancelled' => 'neutral', default => 'warning' }" dot>
                                            {{ ucfirst($leave->status) }}
                                        </x-badge>
                                    </td>
                                    <td class="text-ink-soft">{{ $leave->created_at?->format('M d, Y') ?: '—' }}</td>
                                    <td class="text-right">
                                        <a href="{{ route('employees.leave.show', $leave) }}" class="btn-ghost btn-icon btn-sm" title="View">
                                            <x-icon name="eye" class="size-4" />
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($requests->hasPages())
                <div class="px-5 py-4">
                    {{ $requests->links() }}
                </div>
            @endif
        </x-card>
    </div>
</x-app-layout>