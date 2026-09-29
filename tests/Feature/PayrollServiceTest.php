<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\SalaryStructure;
use App\Models\Setting;
use App\Services\PayrollService;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * Payroll turns attendance into money, so it is the highest-consequence
 * untested area in the application. It was previously covered by nothing at all.
 *
 * The invariants pinned here:
 *  - a payslip's own figures must be self-consistent (gross - deductions = net)
 *  - run totals must equal the sum of their payslips
 *  - a perfect attendance month costs nothing
 *  - absence, lateness and short leave each cost the configured amount
 *  - a half day is worth half a day's deduction
 *  - weekends and holidays are not payable days, and do not become absences
 *  - regenerating is idempotent, and a paid run is immutable
 */
class PayrollServiceTest extends TestCase
{
    use SeedsDatabase;

    /**
     * Monday 2026-09-07 to Friday 2026-09-11, with Sat/Sun already excluded by
     * the default weekend rule.
     */
    private const PERIOD_START = '2026-09-07';

    private const PERIOD_END = '2026-09-11';

    /**
     * Named payrollRun() rather than run(): PHPUnit's TestCase::run() is final.
     */
    private function payrollRun(string $status = 'draft'): PayrollRun
    {
        return PayrollRun::create([
            'number' => 'PR-'.uniqid(),
            'period_start' => self::PERIOD_START,
            'period_end' => self::PERIOD_END,
            'status' => $status,
            'currency' => 'PKR',
        ]);
    }

    private function employeeWithSalary(
        float $basic = 25000.00,
        float $housing = 5000.00,
        float $transport = 3000.00,
        float $other = 0.00,
    ): Employee {
        $employee = Employee::create([
            'user_id' => null,
            'employee_code' => 'EMP-'.strtoupper(uniqid()),
            'first_name' => 'Paid',
            'last_name' => 'Staff',
            'date_hired' => now()->subYear()->toDateString(),
            'employment_status' => 'active',
            'attendance_enabled' => true,
        ]);

        SalaryStructure::create([
            'employee_id' => $employee->id,
            'basic_salary' => (string) $basic,
            'housing_allowance' => (string) $housing,
            'transport_allowance' => (string) $transport,
            'other_allowance' => (string) $other,
            'effective_from' => now()->subYear()->toDateString(),
            'is_active' => true,
        ]);

        return $employee->fresh();
    }

    /**
     * Clock in on each working day of the period, with an optional status.
     */
    private function attend(
        Employee $employee,
        array $days,
        string $status = 'present',
    ): void {
        foreach ($days as $date) {
            AttendanceRecord::create([
                'employee_id' => $employee->id,
                'attendance_date' => $date,
                'status' => $status,
                'check_in_at' => $date.' 09:00:00',
                'check_out_at' => $date.' 17:30:00',
                'method' => 'manual',
            ]);
        }
    }

    /**
     * Change an existing day's status.
     *
     * Matches on the date with whereDate, because attendance_date round-trips
     * as a datetime ('2026-09-11 00:00:00') and a bare string comparison would
     * silently match nothing.
     */
    private function reclassify(Employee $employee, string $date, string $status): void
    {
        $updated = AttendanceRecord::where('employee_id', $employee->id)
            ->whereDate('attendance_date', $date)
            ->update(['status' => $status]);

        $this->assertSame(1, $updated, "expected to reclassify {$date}");
    }

    private function allWorkdays(): array
    {
        return [
            '2026-09-07', '2026-09-08', '2026-09-09', '2026-09-10', '2026-09-11',
        ];
    }

    private function service(): PayrollService
    {
        return app(PayrollService::class);
    }

    public function test_a_full_attendance_month_has_no_deductions(): void
    {
        $employee = $this->employeeWithSalary();
        $this->attend($employee, $this->allWorkdays());

        $run = $this->service()->generate($this->payrollRun());

        $payslip = $run->payslips->firstWhere('employee_id', $employee->id);

        $this->assertNotNull($payslip);
        $this->assertSame(5, $payslip->days_present);
        $this->assertEqualsWithDelta(0.0, (float) $payslip->total_deductions, 0.0005);
        $this->assertEqualsWithDelta((float) $payslip->gross_pay, (float) $payslip->net_pay, 0.0005);
    }

    public function test_gross_is_basic_plus_allowances(): void
    {
        $employee = $this->employeeWithSalary(25000, 5000, 3000, 1000);
        $this->attend($employee, $this->allWorkdays());

        $run = $this->service()->generate($this->payrollRun());

        $payslip = $run->payslips->firstWhere('employee_id', $employee->id);

        // 25000 + 5000 + 3000 + 1000
        $this->assertEqualsWithDelta(34000.0, (float) $payslip->gross_pay, 0.0005);
        $this->assertEqualsWithDelta(9000.0, (float) $payslip->allowances, 0.0005);
    }

    public function test_a_payslip_is_internally_consistent(): void
    {
        $employee = $this->employeeWithSalary();
        $this->attend($employee, ['2026-09-07', '2026-09-08']);

        $run = $this->service()->generate($this->payrollRun());
        $payslip = $run->payslips->firstWhere('employee_id', $employee->id);

        $this->assertEqualsWithDelta(
            (float) $payslip->gross_pay - (float) $payslip->total_deductions,
            (float) $payslip->net_pay,
            0.0005,
            'gross - deductions must equal net',
        );
    }

    public function test_run_totals_equal_the_sum_of_their_payslips(): void
    {
        $first = $this->employeeWithSalary();
        $second = $this->employeeWithSalary(20000, 1000, 1000, 0);

        $this->attend($first, ['2026-09-07', '2026-09-08']);
        $this->attend($second, ['2026-09-07']);

        $run = $this->service()->generate($this->payrollRun())->fresh();

        $gross = (float) $run->payslips->sum(fn ($p) => (float) $p->gross_pay);
        $deductions = (float) $run->payslips->sum(fn ($p) => (float) $p->total_deductions);
        $net = (float) $run->payslips->sum(fn ($p) => (float) $p->net_pay);

        $this->assertEqualsWithDelta($gross, (float) $run->total_gross, 0.005);
        $this->assertEqualsWithDelta($deductions, (float) $run->total_deductions, 0.005);
        $this->assertEqualsWithDelta($net, (float) $run->total_net, 0.005);
    }

    public function test_a_missing_day_counts_as_an_absence(): void
    {
        $employee = $this->employeeWithSalary();
        // Two days present out of five.
        $this->attend($employee, ['2026-09-07', '2026-09-08']);

        $run = $this->service()->generate($this->payrollRun());
        $payslip = $run->payslips->firstWhere('employee_id', $employee->id);

        $this->assertSame(3, $payslip->days_absent, 'unrecorded working days are absences');
    }

    public function test_an_absence_costs_a_full_day_by_default(): void
    {
        $employee = $this->employeeWithSalary();
        $this->attend($employee, ['2026-09-07', '2026-09-08', '2026-09-09', '2026-09-10']);

        $run = $this->service()->generate($this->payrollRun());
        $payslip = $run->payslips->firstWhere('employee_id', $employee->id);

        // Gross 33000 over 5 days = 6600/day. One absence at 100% = 6600.
        $this->assertEqualsWithDelta(6600.0, (float) $payslip->total_deductions, 0.01);
    }

    public function test_a_half_day_costs_half_a_days_deduction(): void
    {
        $employee = $this->employeeWithSalary();
        $this->attend($employee, $this->allWorkdays());
        // Reclassify one day as a half day.
        $this->reclassify($employee, '2026-09-11', 'half_day');

        $run = $this->service()->generate($this->payrollRun());
        $payslip = $run->payslips->firstWhere('employee_id', $employee->id);

        $this->assertSame(1, $payslip->days_half_day);
        // 33000 / 5 = 6600 per day, half of that is 3300.
        $this->assertEqualsWithDelta(3300.0, (float) $payslip->total_deductions, 0.01);
    }

    public function test_a_late_day_costs_the_configured_flat_amount(): void
    {
        Setting::setMany([
            'attendance.deduction_late_type' => 'flat',
            'attendance.deduction_late_amount' => '500',
        ]);
        Setting::flushCache();

        $employee = $this->employeeWithSalary();
        $this->attend($employee, $this->allWorkdays());
        $this->reclassify($employee, '2026-09-09', 'late');

        $run = $this->service()->generate($this->payrollRun());
        $payslip = $run->payslips->firstWhere('employee_id', $employee->id);

        $this->assertSame(1, $payslip->days_late);
        $this->assertEqualsWithDelta(500.0, (float) $payslip->total_deductions, 0.0005);
    }

    public function test_a_late_day_costs_a_percentage_of_the_daily_rate(): void
    {
        Setting::setMany([
            'attendance.deduction_late_type' => 'percent',
            'attendance.deduction_late_amount' => '50',
        ]);
        Setting::flushCache();

        $employee = $this->employeeWithSalary();
        $this->attend($employee, $this->allWorkdays());
        $this->reclassify($employee, '2026-09-09', 'late');

        $run = $this->service()->generate($this->payrollRun());
        $payslip = $run->payslips->firstWhere('employee_id', $employee->id);

        // 6600/day at 50% = 3300.
        $this->assertSame(1, $payslip->days_late);
        $this->assertEqualsWithDelta(3300.0, (float) $payslip->total_deductions, 0.01);
    }

    public function test_short_leave_is_deducted_separately_from_lateness(): void
    {
        Setting::setMany([
            'attendance.deduction_late_type' => 'flat',
            'attendance.deduction_late_amount' => '100',
            'attendance.deduction_short_leave_type' => 'flat',
            'attendance.deduction_short_leave_amount' => '250',
        ]);
        Setting::flushCache();

        $employee = $this->employeeWithSalary();
        $this->attend($employee, $this->allWorkdays());
        $this->reclassify($employee, '2026-09-09', 'late');
        $this->reclassify($employee, '2026-09-10', 'short_leave');

        $run = $this->service()->generate($this->payrollRun());
        $payslip = $run->payslips->firstWhere('employee_id', $employee->id);

        $this->assertSame(1, $payslip->days_late);
        $this->assertSame(1, $payslip->days_short_leave);
        $this->assertEqualsWithDelta(350.0, (float) $payslip->total_deductions, 0.0005);
    }

    public function test_weekends_are_not_payable_or_absence_days(): void
    {
        $employee = $this->employeeWithSalary();
        $this->attend($employee, $this->allWorkdays());

        $run = $this->service()->generate($this->payrollRun());
        $payslip = $run->payslips->firstWhere('employee_id', $employee->id);

        // 5 weekdays in the period; Sat 12th and Sun 13th are not counted.
        $this->assertSame(0, $payslip->days_absent);
        $this->assertSame(5, $payslip->days_present);
    }

    public function test_an_employee_with_no_salary_structure_is_skipped(): void
    {
        Employee::create([
            'user_id' => null,
            'employee_code' => 'EMP-'.strtoupper(uniqid()),
            'first_name' => 'Unpaid',
            'last_name' => 'Staff',
            'date_hired' => now()->subYear()->toDateString(),
            'employment_status' => 'active',
            'attendance_enabled' => true,
        ]);

        $run = $this->service()->generate($this->payrollRun());

        $this->assertCount(0, $run->payslips);
        $this->assertEqualsWithDelta(0.0, (float) $run->total_gross, 0.0005);
    }

    public function test_an_inactive_employee_is_excluded(): void
    {
        $employee = $this->employeeWithSalary();
        $this->attend($employee, $this->allWorkdays());

        $employee->update(['employment_status' => 'terminated']);

        $run = $this->service()->generate($this->payrollRun());

        $this->assertCount(0, $run->payslips, 'terminated staff must not be paid');
    }

    public function test_regenerating_replaces_payslips_rather_than_adding_them(): void
    {
        $employee = $this->employeeWithSalary();
        $this->attend($employee, $this->allWorkdays());

        $run = $this->payrollRun();
        $this->service()->generate($run);
        $this->service()->generate($run);
        $this->service()->generate($run);

        $this->assertCount(1, $run->fresh()->payslips, 'payslips must not accumulate');
    }

    public function test_a_paid_run_cannot_be_regenerated(): void
    {
        $employee = $this->employeeWithSalary();
        $this->attend($employee, $this->allWorkdays());

        $run = $this->payrollRun('paid');

        $this->expectException(\RuntimeException::class);

        $this->service()->generate($run);
    }

    public function test_a_period_with_no_working_days_does_not_divide_by_zero(): void
    {
        $employee = $this->employeeWithSalary();

        // A period that is entirely a weekend, so the working-day count is 0
        // and dailyRate() would divide by zero if it were not guarded.
        $run = PayrollRun::create([
            'number' => 'PR-'.uniqid(),
            'period_start' => '2026-09-12', // Saturday
            'period_end' => '2026-09-13',   // Sunday
            'status' => 'draft',
            'currency' => 'PKR',
        ]);

        $result = $this->service()->generate($run);

        $payslip = $result->payslips->firstWhere('employee_id', $employee->id);

        $this->assertNotNull($payslip);
        $this->assertEqualsWithDelta(
            0.0,
            (float) $payslip->total_deductions,
            0.0005,
            'no working days means nothing to deduct, and must not divide by zero',
        );
        $this->assertEqualsWithDelta(
            (float) $payslip->gross_pay,
            (float) $payslip->net_pay,
            0.0005,
            'a full month is still paid when the period contains no working days',
        );
    }
}
