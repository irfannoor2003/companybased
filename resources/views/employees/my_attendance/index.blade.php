<x-app-layout :pageTitle="'My Attendance'">
    <x-slot name="header">
        <x-page-header title="My Attendance" description="Record and review your own attendance for this month." icon="clock" />
    </x-slot>

    @include('employees._tabs')

    @if (! $employee)
        <div class="mt-6">
            <x-card>
                <x-empty-state icon="clock" title="No employee profile" description="Your account is not linked to an employee profile, so attendance cannot be recorded." />
            </x-card>
        </div>
    @else
        <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
            <x-card title="Today" description="Status for {{ now()->format('l, M d Y') }}">
                @php
                    $status = $today?->status ?? 'pending';
                    $action = ($today && $today->check_in_at && ! $today->check_out_at) ? 'Clock out' : 'Clock in';
                @endphp
                <div class="flex flex-col gap-4">
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-ink-faint">Status</span>
                        <x-employees.status-badge :status="$status" />
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-ink-faint">Check in</span>
                        <span class="font-mono text-ink">{{ $today?->check_in_at?->format('H:i') ?: '—' }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-ink-faint">Check out</span>
                        <span class="font-mono text-ink">{{ $today?->check_out_at?->format('H:i') ?: '—' }}</span>
                    </div>
                    @if ($today?->work_minutes)
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-ink-faint">Worked</span>
                            <span class="font-mono text-ink">{{ gmdate('H:i', $today->work_minutes * 60) }}</span>
                        </div>
                    @endif
                    @if (auth()->user()->can('employees.my_attendance.mark'))
                        <form method="POST" action="{{ route('employees.my_attendance.mark') }}">
                            @csrf
                            <x-button type="submit" icon="clock" class="w-full">{{ $action }}</x-button>
                        </form>
                    @endif
                </div>
            </x-card>

            <x-card title="This month" :padding="false" class="lg:col-span-2">
                @php
                    $present = $records->where('status', 'present')->count();
                    $late = $records->where('status', 'late')->count();
                    $shortLeave = $records->where('status', 'short_leave')->count();
                    $halfDay = $records->where('status', 'half_day')->count();
                    $absent = $records->where('status', 'absent')->count();
                @endphp
                <div class="grid grid-cols-5 gap-4 border-b border-line px-5 py-4">
                    <x-stat-card label="Present" :value="$present" icon="check" tone="success" />
                    <x-stat-card label="Late" :value="$late" icon="clock" tone="warning" />
                    <x-stat-card label="Short leave" :value="$shortLeave" icon="clock" tone="info" />
                    <x-stat-card label="Half day" :value="$halfDay" icon="clock" tone="warning" />
                    <x-stat-card label="Absent" :value="$absent" icon="x" tone="danger" />
                </div>

                @if ($records->isEmpty())
                    <p class="px-5 py-6 text-sm text-ink-faint">No attendance records this month.</p>
                @else
                    <div class="table-wrap !border-0 !rounded-none">
                        <table class="table-base">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>In</th>
                                    <th>Out</th>
                                    <th class="text-right">Work</th>
                                    <th>Method</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($records as $record)
                                    <tr>
                                        <td class="text-ink">{{ $record->attendance_date->format('Y-m-d') }}</td>
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
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-card>
        </div>
    @endif
</x-app-layout>