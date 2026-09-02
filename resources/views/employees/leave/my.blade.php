<x-app-layout :pageTitle="'My Leave'">
    <x-slot name="header">
        <x-page-header title="My Leave" description="Track your leave requests and their approval status." icon="calendar">
            <x-slot name="actions">
                @if (auth()->user()->can('employees.my_leave.create'))
                    <x-button href="{{ route('employees.leave.my.create') }}" icon="plus">New request</x-button>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6">
        <x-card :padding="false">
            @if (! $employee)
                <x-empty-state icon="calendar" title="No employee profile" description="Your account is not linked to an employee profile, so leave cannot be requested." />
            @elseif ($requests->isEmpty())
                <x-empty-state icon="calendar" title="No leave requests" description="Submit a request to get started."
                    action="{{ auth()->user()->can('employees.my_leave.create') ? 'New request' : null }}"
                    :action-href="auth()->user()->can('employees.my_leave.create') ? route('employees.leave.my.create') : null" />
            @else
                <div class="table-wrap !border-0 !rounded-none">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th>Dates</th>
                                <th class="text-right">Days</th>
                                <th>Reason</th>
                                <th>Status</th>
                                <th>Reviewed by</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($requests as $leave)
                                <tr>
                                    <td class="font-medium text-ink">{{ ucfirst($leave->leave_type) }}</td>
                                    <td class="text-ink-soft">{{ $leave->start_date?->format('M d, Y') }} → {{ $leave->end_date?->format('M d, Y') }}</td>
                                    <td class="text-right text-ink-soft">{{ $leave->days }}</td>
                                    <td class="max-w-xs">
                                        <span class="block truncate text-ink-soft" title="{{ $leave->reason }}">{{ $leave->reason }}</span>
                                    </td>
                                    <td>
                                        <x-badge :color="match ($leave->status) { 'approved' => 'success', 'rejected' => 'danger', 'cancelled' => 'neutral', default => 'warning' }" dot>
                                            {{ ucfirst($leave->status) }}
                                        </x-badge>
                                    </td>
                                    <td class="text-ink-soft">{{ $leave->reviewer?->name ?: '—' }}</td>
                                    <td class="text-right">
                                        @if ($leave->status === 'pending')
                                            <form method="POST" action="{{ route('employees.leave.my.cancel', $leave) }}" onsubmit="return confirm('Cancel this leave request?')">
                                                @csrf
                                                <button type="submit" class="btn-ghost btn-icon btn-sm text-rose-500" title="Cancel request">
                                                    <x-icon name="x" class="size-4" />
                                                </button>
                                            </form>
                                        @else
                                            <span class="text-xs text-ink-faint">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-card>
    </div>
</x-app-layout>