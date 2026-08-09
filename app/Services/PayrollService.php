<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\SalaryStructure;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Generates payroll runs: gross pay from the employee's active salary
 * structure, attendance-driven deductions from the configured rules, and the
 * resulting net pay per employee (payslips). Money stays in decimal strings.
 */
class PayrollService
{
    public function __construct(private readonly AttendanceService $attendance)
    {
    }

    /**
     * (Re)generate payslips for every employee with an active salary structure
     * inside the run's period. Returns the run with totals refreshed.
     */
    public function generate(PayrollRun $run, ?Collection $employees = null): PayrollRun
    {
        $rules = $this->attendance->rules();

        $employees = $employees ?? Employee::with('salaryStructures')->get();

        $workDates = $this->workingDates($run->period_start, $run->period_end, $rules);

        $run->payslips()->delete();

        $totals = ['gross' => 0.0, 'deductions' => 0.0, 'net' => 0.0];

        foreach ($employees as $employee) {
            $structure = $employee->activeSalaryStructure();

            if (! $structure || $structure->effective_from->gt(Carbon::parse($run->period_end))) {
                continue;
            }

            $payslip = $this->buildPayslip($run, $employee, $structure, $workDates, $rules);

            if (! $payslip) {
                continue;
            }

            $totals['gross'] = round($totals['gross'] + (float) $payslip->gross_pay, 2);
            $totals['deductions'] = round($totals['deductions'] + (float) $payslip->total_deductions, 2);
            $totals['net'] = round($totals['net'] + (float) $payslip->net_pay, 2);
        }

        $run->update([
            'total_gross' => (string) $totals['gross'],
            'total_deductions' => (string) $totals['deductions'],
            'total_net' => (string) $totals['net'],
        ]);

        $run->load('payslips.employee');

        return $run;
    }

    public function workingDates($start, $end, array $rules): Collection
    {
        $dates = collect();
        $cursor = Carbon::parse($start)->copy();

        while ($cursor->lte(Carbon::parse($end))) {
            if (! $this->attendance->isWeekend($cursor)) {
                $dates->push($cursor->copy());
            }
            $cursor->addDay();
        }

        return $dates;
    }

    private function buildPayslip(PayrollRun $run, Employee $employee, SalaryStructure $structure, Collection $workDates, array $rules): ?Payslip
    {
        $records = $employee->attendanceRecords()
            ->forPeriod($run->period_start->toDateString(), $run->period_end->toDateString())
            ->get()
            ->keyBy(fn (AttendanceRecord $r) => $r->attendance_date->toDateString());

        $counts = ['present' => 0, 'late' => 0, 'short_leave' => 0, 'half_day' => 0, 'absent' => 0];

        foreach ($workDates as $date) {
            $key = $date->toDateString();
            $record = $records->get($key);

            if (! $record || ! $record->check_in_at) {
                $counts['absent']++;
                continue;
            }

            $status = $record->status ?? 'present';
            if (array_key_exists($status, $counts)) {
                $counts[$status]++;
            } else {
                $counts['present']++;
            }
        }

        $gross = $structure->gross();
        $dailyRate = $this->attendance->dailyRate($gross, $workDates->count());

        $lateDed = $this->attendance->deductionFor('deduction_late_type', 'deduction_late_amount', $dailyRate, $rules) * $counts['late'];
        $shortLeaveDed = $this->attendance->deductionFor('deduction_short_leave_type', 'deduction_short_leave_amount', $dailyRate, $rules) * $counts['short_leave'];
        $absentPerDay = $this->attendance->deductionFor('deduction_absent_type', 'deduction_absent_amount', $dailyRate, $rules);
        $absentDed = $absentPerDay * ($counts['absent'] + (0.5 * $counts['half_day']));

        $attendanceDeductions = round($lateDed + $shortLeaveDed + $absentDed, 2);
        $net = round($gross - $attendanceDeductions, 2);

        return $run->payslips()->create([
            'employee_id' => $employee->id,
            'basic_salary' => (string) $structure->basic_salary,
            'allowances' => (string) $structure->allowances(),
            'gross_pay' => (string) round($gross, 2),
            'days_present' => $counts['present'],
            'days_late' => $counts['late'],
            'days_short_leave' => $counts['short_leave'],
            'days_half_day' => $counts['half_day'],
            'days_absent' => $counts['absent'],
            'attendance_deductions' => (string) $attendanceDeductions,
            'total_deductions' => (string) $attendanceDeductions,
            'net_pay' => (string) $net,
        ]);
    }
}
