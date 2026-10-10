<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\SalaryStructure;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Generates payroll runs: gross pay from the employee's active salary
 * structure, attendance-driven deductions from the configured rules, and the
 * resulting net pay per employee (payslips). Money stays in decimal strings.
 */
class PayrollService
{
    public function __construct(private readonly AttendanceService $attendance) {}

    /**
     * (Re)generate payslips for every employee with an active salary structure
     * inside the run's period. Returns the run with totals refreshed.
     */
    public function generate(PayrollRun $run, ?Collection $employees = null): PayrollRun
    {
        return DB::transaction(function () use ($run, $employees): PayrollRun {
            $run = PayrollRun::query()->lockForUpdate()->findOrFail($run->id);
            if ($run->status === 'paid') {
                throw new \RuntimeException('Paid payroll runs cannot be regenerated.');
            }

            $rules = $this->attendance->rules();
            $employees = ($employees ?? Employee::with('salaryStructures')->where('employment_status', 'active')->get())
                ->filter(fn (Employee $employee) => $employee->employment_status === 'active');

            $workDates = $this->workingDates($run->period_start, $run->period_end, $rules);
            $run->payslips()->forceDelete();

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
        });
    }

    public function workingDates($start, $end, array $rules): Collection
    {
        $dates = collect();
        $cursor = Carbon::parse($start)->copy();

        while ($cursor->lte(Carbon::parse($end))) {
            if ($this->attendance->isWorkingDay($cursor)) {
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

        // Each deduction is a money amount scaled by a headcount, and each product
        // is rounded to the scale before the three are added. Multiplying in float
        // and rounding only the sum leaves a fraction of a rupee in the payslip
        // (12.999999999 vs 13.00) that does not survive a re-read of the column.
        $lateDed = Money::multiply(
            $this->attendance->deductionFor('deduction_late_type', 'deduction_late_amount', $dailyRate, $rules),
            $counts['late'],
        );

        $shortLeaveDed = Money::multiply(
            $this->attendance->deductionFor('deduction_short_leave_type', 'deduction_short_leave_amount', $dailyRate, $rules),
            $counts['short_leave'],
        );

        $absentPerDay = $this->attendance->deductionFor('deduction_absent_type', 'deduction_absent_amount', $dailyRate, $rules);

        // A half day counts as half an absence, hence the 0.5 factor.
        $absenceUnits = Money::add($counts['absent'], Money::multiply('0.5', $counts['half_day']));
        $absentDed = Money::multiply($absentPerDay, $absenceUnits);

        $attendanceDeductions = Money::add($lateDed, $shortLeaveDed, $absentDed);

        /**
         * Deductions are capped at gross pay.
         *
         * The daily rate is gross divided by the number of working days, so a full
         * month of absence multiplies back to very nearly the whole salary -- and
         * because each product is rounded to the money scale before being summed,
         * it can land a fraction above gross. The payslip then shows a *negative*
         * net pay, which is not a thing: nobody owes the company their salary.
         *
         * Clamping here rather than at the posting layer keeps the payslip itself
         * honest, because it is the document a payroll clerk reconciles against.
         */
        if (Money::isGreaterThan($attendanceDeductions, $gross)) {
            $attendanceDeductions = Money::of($gross);
        }

        /**
         * Statutory withholding, computed from the employee's own rates.
         *
         * Deliberately separate from attendance above. The two are different kinds
         * of money: attendance is a smaller wage earned, which comes back out of
         * the expense; statutory withholding is money held on behalf of an
         * authority, which is a liability and never touches the P&L at all.
         *
         * Both rates default to zero, so a structure that has not been configured
         * produces a payslip with no withholding rather than a fabricated one.
         */
        $statutoryDeductions = Money::add(
            Money::percentageOf($gross, (string) ($structure->statutory_tax_percent ?? 0)),
            Money::percentageOf($gross, (string) ($structure->statutory_social_percent ?? 0)),
        );

        $totalDeductions = Money::add($attendanceDeductions, $statutoryDeductions);

        // The cap is applied to the combined total: whatever the reason, an employee
        // does not pay back more than they earn.
        if (Money::isGreaterThan($totalDeductions, $gross)) {
            $totalDeductions = Money::of($gross);
        }

        $net = Money::subtract($gross, $totalDeductions);

        return $run->payslips()->create([
            'employee_id' => $employee->id,
            'basic_salary' => (string) $structure->basic_salary,
            'allowances' => (string) $structure->allowances(),
            'gross_pay' => Money::of($gross),
            'days_present' => $counts['present'],
            'days_late' => $counts['late'],
            'days_short_leave' => $counts['short_leave'],
            'days_half_day' => $counts['half_day'],
            'days_absent' => $counts['absent'],
            'attendance_deductions' => $attendanceDeductions,
            'statutory_deductions' => $statutoryDeductions,
            'total_deductions' => $totalDeductions,
            'net_pay' => $net,
        ]);
    }
}
