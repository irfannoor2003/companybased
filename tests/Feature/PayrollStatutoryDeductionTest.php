<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\SalaryStructure;
use App\Services\Accounting\LedgerPosting;
use App\Services\PayrollService;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * Statutory withholding is a real figure, not gross minus net.
 *
 * The controller used to derive "tax withheld" as gross less net. That difference
 * also contains lateness and absence, which were never tax: an absent day is a
 * wage not earned, which comes back out of the wage expense, not a debt to the
 * revenue department.
 *
 * Posting the whole difference as tax meant:
 *
 *  - the company appeared to owe tax it never owed, and
 *  - the wage expense stayed overstated, because the attendance element was
 *    parked in a tax liability instead of reducing the cost of employment.
 *
 * Both are balance-sheet errors, and neither was visible in the payslip totals.
 */
class PayrollStatutoryDeductionTest extends TestCase
{
    use SeedsDatabase;

    private function employee(): Employee
    {
        return Employee::create([
            'first_name' => 'Payroll',
            'last_name' => 'Subject',
            'employee_code' => 'EMP-'.uniqid(),
            // NOT NULL with no default: part of the record, not an annotation.
            'date_hired' => now()->subYear()->toDateString(),
            'employment_status' => 'active',
            'is_active' => true,
        ]);
    }

    private function structure(Employee $employee, array $overrides = []): SalaryStructure
    {
        return SalaryStructure::create(array_merge([
            'employee_id' => $employee->id,
            'effective_from' => now()->subYear()->toDateString(),
            'basic_salary' => '100000.00',
            'housing_allowance' => '0',
            'transport_allowance' => '0',
            'other_allowance' => '0',
            'is_active' => true,
        ], $overrides));
    }

    private function payrollRun(): PayrollRun
    {
        return PayrollRun::create([
            'number' => 'PR-'.uniqid(),
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'status' => 'draft',
            'currency' => 'PKR',
        ]);
    }

    /**
     * With no rates configured, nothing is withheld. An unconfigured payroll must
     * say "none" rather than invent a liability.
     */
    public function test_an_unconfigured_structure_withholds_nothing(): void
    {
        $employee = $this->employee();
        $this->structure($employee);
        $run = $this->payrollRun();

        app(PayrollService::class)->generate($run);

        $payslip = $run->payslips()->firstOrFail();

        $this->assertSame('0.00', $payslip->statutory_deductions);
    }

    /**
     * A configured rate withholds the right amount and it lands in the total.
     */
    public function test_a_configured_rate_is_withheld_and_counted(): void
    {
        $employee = $this->employee();
        $this->structure($employee, ['statutory_tax_percent' => '5.0000']);
        $run = $this->payrollRun();

        app(PayrollService::class)->generate($run);

        $payslip = $run->payslips()->firstOrFail();
        $expected = round((float) $payslip->gross_pay * 0.05, 2);

        $this->assertSame(
            number_format($expected, 2, '.', ''),
            (string) $payslip->statutory_deductions,
            'the configured rate was not withheld',
        );

        $this->assertSame(
            round((float) $payslip->net_pay, 2),
            round((float) $payslip->gross_pay - (float) $payslip->total_deductions, 2),
            'net pay does not equal gross less total deductions',
        );
    }

    public function test_tax_and_social_contributions_are_withheld_separately(): void
    {
        $employee = $this->employee();
        $this->structure($employee, [
            'statutory_tax_percent' => '5.0000',
            'statutory_social_percent' => '2.0000',
        ]);
        $run = $this->payrollRun();

        app(PayrollService::class)->generate($run);

        $payslip = $run->payslips()->firstOrFail();
        $expected = round((float) $payslip->gross_pay * 0.07, 2);

        $this->assertSame(
            number_format($expected, 2, '.', ''),
            (string) $payslip->statutory_deductions,
        );
    }

    /**
     * The point of the whole change, asserted on the ledger.
     *
     * With attendance loss and no statutory rate, nothing may reach Payroll Tax
     * Payable. The attendance element belongs on the wage expense.
     */
    public function test_attendance_loss_does_not_create_a_tax_liability(): void
    {
        $employee = $this->employee();
        $this->structure($employee);
        $run = $this->payrollRun();

        app(PayrollService::class)->generate($run);

        $payslip = $run->payslips()->firstOrFail();
        $attendance = (float) $payslip->attendance_deductions;

        // Force a non-zero attendance loss so the case is actually exercised.
        $payslip->update([
            'attendance_deductions' => '10000.00',
            'total_deductions' => '10000.00',
            'net_pay' => (string) ((float) $payslip->gross_pay - 10000),
        ]);
        $run->update([
            'total_gross' => $payslip->gross_pay,
            'total_net' => (string) ((float) $payslip->gross_pay - 10000),
        ]);

        app(LedgerPosting::class)->payrollRunApproved($run->fresh(), '0.00');

        $taxLiability = Account::where('code', '2250')->first()?->balance() ?? 0.0;

        $this->assertSame(
            0.0,
            round($taxLiability, 2),
            'an attendance deduction was posted as tax payable',
        );

        // And the wage expense absorbed it. Debit-positive convention: Salaries
        // and Wages is debited gross and credited the attendance element, so the
        // net expense is gross LESS what was not earned.
        $expense = Account::where('code', '6200')->first()?->balance() ?? 0.0;

        $this->assertSame(
            round((float) $run->total_gross - 10000, 2),
            round($expense, 2),
            'the wage expense does not reflect the attendance credit',
        );
    }

    /**
     * A configured rate does create the liability — the account is not simply
     * unused. Guards against "fixing" the bug by never posting tax at all.
     */
    public function test_a_configured_rate_does_create_a_tax_liability(): void
    {
        $employee = $this->employee();
        $this->structure($employee, ['statutory_tax_percent' => '10.0000']);
        $run = $this->payrollRun();

        app(PayrollService::class)->generate($run);
        $run->update([
            'total_gross' => $run->payslips()->first()->gross_pay,
            'total_net' => $run->payslips()->first()->net_pay,
        ]);

        $withheld = (float) $run->payslips()->first()->statutory_deductions;

        app(LedgerPosting::class)->payrollRunApproved($run->fresh(), (string) $withheld);

        $taxLiability = Account::where('code', '2250')->first()?->balance() ?? 0.0;

        // Debit-positive: Payroll Tax Payable is CREDITED by the withholding, so
        // `debits - credits` reports it as negative. The magnitude is what matters.
        $this->assertSame(
            -round($withheld, 2),
            round($taxLiability, 2),
            'the configured withholding did not reach Payroll Tax Payable',
        );
    }
}
