<x-app-layout :pageTitle="'Attendance Report'">
    <x-slot name="header">
        <x-page-header title="Attendance Report" description="Period summary of attendance with estimated salary deductions." icon="reports">
            <x-slot name="actions">
                <x-button href="{{ route('employees.attendance.index') }}" variant="ghost" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    @include('employees._tabs')

    <div class="mt-6">
        <x-card :padding="false">
            <form method="GET" action="{{ route('employees.attendance.report') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
                @php $currentPeriod = request('period', 'monthly'); @endphp
                <input type="hidden" name="period" value="{{ $currentPeriod }}">

                <div class="flex items-center gap-1 rounded-lg border border-line bg-surface p-1">
                    @foreach (['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'custom' => 'Custom'] as $value => $label)
                        <button type="button"
                            onclick="this.form.elements.period.value='{{ $value }}'; this.form.submit();"
                            class="rounded-md px-3 py-1.5 text-sm font-medium transition-colors {{ $currentPeriod === $value ? 'bg-primary text-white shadow-sm' : 'text-ink-soft hover:text-ink' }}">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>

                <div class="{{ $currentPeriod === 'custom' ? '' : 'hidden' }}" id="custom-dates">
                    <div class="flex items-end gap-3">
                        <x-input name="from" label="From" type="date" :value="request('from')" size="sm" />
                        <x-input name="to" label="To" type="date" :value="request('to')" size="sm" />
                    </div>
                </div>

                <div>
                    <x-select name="department_id">
                        <option value="">All departments</option>
                        @foreach ($departments as $department)
                            <option value="{{ $department->id }}" @selected($departmentId == $department->id)>{{ $department->name }}</option>
                        @endforeach
                    </x-select>
                </div>

                <x-button type="submit" size="md" icon="reports">Run report</x-button>

                @if (auth()->user()->can('employees.attendance.export'))
                    <x-button href="{{ route('employees.attendance.report.export', array_merge(request()->query(), ['format' => 'csv'])) }}" variant="secondary" size="sm" icon="download">CSV</x-button>
                    <x-button href="{{ route('employees.attendance.report.export', array_merge(request()->query(), ['format' => 'json'])) }}" variant="secondary" size="sm" icon="download">JSON</x-button>
                @endif
            </form>
        </x-card>
    </div>

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-6">
        <x-stat-card label="Present" :value="$totals['present']" icon="check" tone="success" />
        <x-stat-card label="Late" :value="$totals['late']" icon="clock" tone="warning" />
        <x-stat-card label="Short leave" :value="$totals['short_leave']" icon="clock" tone="info" />
        <x-stat-card label="Half day" :value="$totals['half_day']" icon="clock" tone="warning" />
        <x-stat-card label="Absent" :value="$totals['absent']" icon="x" tone="danger" />
        <x-stat-card label="Est. deductions" :value="money($totals['deductions'])" icon="money" tone="danger" />
    </div>

    <div class="mt-6">
        <x-card :padding="false">
            <div class="flex items-center justify-between border-b border-line px-5 py-4">
                <p class="text-sm text-ink-soft">Showing <span class="font-medium text-ink">{{ Carbon\Carbon::parse($from)->format('M d') }} – {{ Carbon\Carbon::parse($to)->format('M d, Y') }}</span></p>
            </div>

            @if ($summary->isEmpty())
                <x-empty-state icon="reports" title="No data" description="No employees match the selected filters for this period." />
            @else
                <div class="table-wrap !border-0 !rounded-none">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Department</th>
                                <th class="text-center">Present</th>
                                <th class="text-center">Late</th>
                                <th class="text-center">Short leave</th>
                                <th class="text-center">Half day</th>
                                <th class="text-center">Absent</th>
                                <th class="text-right">Deductions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($summary as $row)
                                <tr>
                                    <td>
                                        <span class="font-medium text-ink">{{ $row['name'] }}</span>
                                        <span class="block font-mono text-xs text-ink-faint">{{ $row['code'] }}</span>
                                    </td>
                                    <td class="text-ink-soft">{{ $row['department'] ?: '—' }}</td>
                                    <td class="text-center text-ink">{{ $row['present'] }}</td>
                                    <td class="text-center text-ink-soft">{{ $row['late'] }}</td>
                                    <td class="text-center text-ink-soft">{{ $row['short_leave'] }}</td>
                                    <td class="text-center text-ink-soft">{{ $row['half_day'] }}</td>
                                    <td class="text-center text-ink-soft">{{ $row['absent'] }}</td>
                                    <td class="text-right font-medium text-ink">{{ money($row['deductions']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="2" class="text-right font-semibold text-ink">Totals</td>
                                <td class="text-center font-semibold text-ink">{{ $totals['present'] }}</td>
                                <td class="text-center font-semibold text-ink">{{ $totals['late'] }}</td>
                                <td class="text-center font-semibold text-ink">{{ $totals['short_leave'] }}</td>
                                <td class="text-center font-semibold text-ink">{{ $totals['half_day'] }}</td>
                                <td class="text-center font-semibold text-ink">{{ $totals['absent'] }}</td>
                                <td class="text-right font-semibold text-ink">{{ money($totals['deductions']) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </x-card>
    </div>
</x-app-layout>