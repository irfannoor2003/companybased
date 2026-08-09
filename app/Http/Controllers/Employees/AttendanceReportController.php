<?php

namespace App\Http\Controllers\Employees;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Services\AttendanceService;
use App\Services\PayrollService;
use App\Support\ExportsCsv;
use App\Support\ExportsJson;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttendanceReportController extends Controller
{
    use ExportsCsv;
    use ExportsJson;

    public function __construct(private readonly AttendanceService $attendance)
    {
    }

    public function index(Request $request): View
    {
        [$from, $to] = $this->range($request);

        $query = AttendanceRecord::query()
            ->with('employee.department')
            ->forPeriod($from, $to);

        $employeeId = $request->integer('employee_id') ?: null;
        $departmentId = $request->integer('department_id') ?: null;

        if ($employeeId) {
            $query->where('employee_id', $employeeId);
        } elseif ($departmentId) {
            $query->whereHas('employee', fn ($q) => $q->where('department_id', $departmentId));
        }

        $records = $query->get();

        $employees = Employee::query()
        ->with('department')
        ->when($departmentId, fn ($q) => $q->where('department_id', $departmentId))
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'employee_code', 'department_id']);

        $summary = $this->summarize($employees, $records, $from, $to);

        $departments = Department::query()->orderBy('name')->get(['id', 'name']);

        $totals = [
            'present' => $summary->sum('present'),
            'late' => $summary->sum('late'),
            'short_leave' => $summary->sum('short_leave'),
            'half_day' => $summary->sum('half_day'),
            'absent' => $summary->sum('absent'),
            'deductions' => round((float) $summary->sum('deductions'), 2),
        ];

        return view('employees.attendance.report', compact('summary', 'records', 'departments', 'from', 'to', 'totals', 'employeeId', 'departmentId'));
    }

    public function export(Request $request): StreamedResponse
    {
        [$from, $to] = $this->range($request);

        $query = AttendanceRecord::query()
            ->with('employee.department')
            ->forPeriod($from, $to);

        $employeeId = $request->integer('employee_id') ?: null;
        $departmentId = $request->integer('department_id') ?: null;

        if ($employeeId) {
            $query->where('employee_id', $employeeId);
        } elseif ($departmentId) {
            $query->whereHas('employee', fn ($q) => $q->where('department_id', $departmentId));
        }

        $records = $query->get();

        $employees = Employee::query()
        ->with('department')
        ->when($departmentId, fn ($q) => $q->where('department_id', $departmentId))
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'employee_code', 'department_id']);

        $summary = $this->summarize($employees, $records, $from, $to);

        $rows = $summary->map(fn ($row) => [
            'employee_code' => $row['code'],
            'employee' => $row['name'],
            'department' => $row['department'],
            'present' => $row['present'],
            'late' => $row['late'],
            'short_leave' => $row['short_leave'],
            'half_day' => $row['half_day'],
            'absent' => $row['absent'],
            'deductions' => $row['deductions'],
        ]);

        $filename = 'attendance-report-'.$from.'-to-'.$to.'.'.($request->query('format') === 'json' ? 'json' : 'csv');

        return $request->query('format') === 'json'
            ? $this->streamJson($filename, $rows)
            : $this->streamCsv($filename, ['Code', 'Employee', 'Department', 'Present', 'Late', 'Short leave', 'Half day', 'Absent', 'Deductions'], $rows->values());
    }

    /**
     * Aggregate status counts + estimated salary deductions per employee.
     */
    private function summarize($employees, $records, string $from, string $to): \Illuminate\Support\Collection
    {
        $rules = $this->attendance->rules();
        $workDates = (new PayrollService($this->attendance))->workingDates($from, $to, $rules);
        $byEmployee = $records->groupBy('employee_id');

        return $employees->map(function (Employee $employee) use ($byEmployee, $rules, $workDates) {
            $employeeRecords = $byEmployee->get($employee->id, collect())
                ->keyBy(fn (AttendanceRecord $r) => $r->attendance_date->toDateString());

            $counts = ['present' => 0, 'late' => 0, 'short_leave' => 0, 'half_day' => 0, 'absent' => 0];

            foreach ($workDates as $date) {
                $record = $employeeRecords->get($date->toDateString());

                if (! $record || ! $record->check_in_at) {
                    $counts['absent']++;
                    continue;
                }

                $status = $record->status ?? 'present';
                $counts[array_key_exists($status, $counts) ? $status : 'present']++;
            }

            $structure = $employee->activeSalaryStructure();
            $dailyRate = $structure
                ? $this->attendance->dailyRate($structure->gross(), $workDates->count())
                : 0.0;

            $lateDed = $this->attendance->deductionFor('deduction_late_type', 'deduction_late_amount', $dailyRate, $rules) * $counts['late'];
            $shortLeaveDed = $this->attendance->deductionFor('deduction_short_leave_type', 'deduction_short_leave_amount', $dailyRate, $rules) * $counts['short_leave'];
            $absentPerDay = $this->attendance->deductionFor('deduction_absent_type', 'deduction_absent_amount', $dailyRate, $rules);
            $deductions = round($lateDed + $shortLeaveDed + ($absentPerDay * ($counts['absent'] + (0.5 * $counts['half_day']))), 2);

            return [
                'code' => $employee->employee_code,
                'name' => $employee->fullName(),
                'department' => $employee->department?->name,
                'present' => $counts['present'],
                'late' => $counts['late'],
                'short_leave' => $counts['short_leave'],
                'half_day' => $counts['half_day'],
                'absent' => $counts['absent'],
                'deductions' => $deductions,
            ];
        });
    }

    private function range(Request $request): array
    {
        return match ($request->query('period', 'monthly')) {
            'daily' => [now()->toDateString(), now()->toDateString()],
            'weekly' => [now()->startOfWeek()->toDateString(), now()->endOfWeek()->toDateString()],
            'custom' => [$request->query('from', now()->startOfMonth()->toDateString()), $request->query('to', now()->toDateString())],
            default => [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()],
        };
    }

    private function dateLabel(string $from, string $to): string
    {
        return Carbon::parse($from)->format('M d').' – '.Carbon::parse($to)->format('M d, Y');
    }
}

