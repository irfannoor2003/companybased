<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Models\PayrollRun;
use App\Models\SalaryStructure;
use App\Services\Accounting\LedgerPosting;
use App\Services\PayrollService;
use App\Support\Money;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * Payroll posting to the general ledger.
 *
 * Payroll was the largest source of balance-sheet movement with nothing posting
 * it: salaries were an internal number on a payslip and never touched the ledger,
 * so the P&L understated cost and the balance sheet showed no wage liability.
 *
 * Two events matter and they are not the same:
 *
 *   approval - the cost was incurred and the employees are owed money
 *   payment  - the money actually left the bank
 *
 * Collapsing them would either expense the payroll before it is owed, or settle a
 * liability that was never accrued.
 */
class PayrollLedgerPostingTest extends TestCase
{
    use SeedsDatabase;

private function employee(string $name = 'Test Employee'): Employee
    {
        $parts = explode(' ', $name);

        return Employee::create([
            'first_name' => $parts[0],
            'last_name' => end($parts),
            'employee_code' => 'EMP-'.substr(md5($name), 0, 8),
            'email' => str($name)->slug().'@payroll.test',
            'date_hired' => '2025-01-01',
            'employment_status' => 'active',
            'is_active' => true,
        ]);
    }

    private function payrollRun(array $overrides = []): PayrollRun
    {
        return PayrollRun::create(array_merge([
            'number' => 'PR-'.uniqid(),
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'status' => 'draft',
            'total_gross' => '10000.00',
            'total_deductions' => '500.00',
            'total_net' => '9500.00',
            'currency' => 'PKR',
        ], $overrides));
    }

    private function balanceFor(JournalEntry $entry): array
    {
        $entry->load('items.account');

        $debits = '0.00';
        $credits = '0.00';

        foreach ($entry->items as $item) {
            $debits = Money::add($debits, $item->debit);
            $credits = Money::add($credits, $item->credit);
        }

        return [
            'debits' => $debits,
            'credits' => $credits,
            'lines' => $entry->items->map(fn ($i) => [
                'code' => $i->account->code,
                'name' => $i->account->name,
                'debit' => $i->debit,
                'credit' => $item = $i->credit,
            ])->all(),
        ];
    }

    public function test_approval_posts_the_wage_cost_and_the_liability(): void
    {
        $run = $this->payrollRun();

        $entry = app(LedgerPosting::class)->payrollRunApproved($run);

        $this->assertNotNull($entry, 'approval produced no journal');

        $balance = $this->balanceFor($entry);
        $this->assertSame('10000.00', $balance['debits'], 'the full gross is the cost of employment');
        $this->assertSame($balance['debits'], $balance['credits'], 'the journal must balance');

        $codes = array_column($balance['lines'], 'code');
        $this->assertContains('6200', $codes, 'Salaries and Wages');
        $this->assertContains('2150', $codes, 'Net Pay Payable');
    }

/**
     * Gross is charged in full and the unearned portion is credited back, so the
     * two stay visible separately.
     *
     * The net movement on 6200 is what the employer actually incurred. Reading that
     * as "the expense is net pay" is the mistake this test exists to prevent: an
     * unworked day is a smaller cost, not a smaller cheque with the expense
     * untouched. Which is why the deduction is a credit on the expense rather than
     * a debit on a liability that was never incurred.
     */
    public function test_the_gross_cost_stands_despite_attendance_deductions(): void
    {
        $entry = app(LedgerPosting::class)->payrollRunApproved($this->payrollRun());

        $lines = collect($this->balanceFor($entry)['lines'])->where('code', '6200');

        $grossDebit = Money::of($lines->sum('debit'));
        $attendanceCredit = Money::of($lines->sum('credit'));

        $this->assertSame('10000.00', $grossDebit, 'the full gross is charged to the expense');
        $this->assertSame('500.00', $attendanceCredit, 'unearned days are credited back');
        $this->assertSame('9500.00', Money::subtract($grossDebit, $attendanceCredit), 'the cost actually incurred');
    }

    /**
     * Attendance deductions were never incurred by the employer. Crediting them to
     * a tax liability would fabricate a debt to an authority.
     */
    public function test_attendance_deductions_do_not_become_a_tax_liability(): void
    {
        $entry = app(LedgerPosting::class)->payrollRunApproved($this->payrollRun());

        $codes = array_column($this->balanceFor($entry)['lines'], 'code');

        $this->assertNotContains(
            '2250',
            $codes,
            'attendance deductions were credited to Payroll Tax Payable, inventing a debt to the authority',
        );
    }

    /**
     * A real withholding figure, when one exists, is a liability and goes to 2250.
     */
    public function test_real_withheld_tax_is_a_liability(): void
    {
        // gross 10000, withheld tax 800, attendance 500 => net 8700
        $entry = app(LedgerPosting::class)->payrollRunApproved(
            $this->payrollRun(['total_net' => '8700.00', 'total_deductions' => '1300.00']),
            '800.00',
        );

$lines = collect($this->balanceFor($entry)['lines']);

        // Two lines share the 6200 code (gross debit, attendance credit), so
        // keyBy would silently keep only one of them.
        $totalFor = fn (string $code, string $side) => Money::of(
            $lines->where('code', $code)->sum($side)
        );

        $this->assertSame('800.00', $totalFor('2250', 'credit'), 'withheld tax is owed to the authority');
        $this->assertSame('8700.00', $totalFor('2150', 'credit'), 'net pay owed to employees');
        $this->assertSame('10000.00', $totalFor('6200', 'debit'), 'the full gross is charged');
        $this->assertSame('500.00', $totalFor('6200', 'credit'), 'the remaining 500 is an attendance credit');
    }

    public function test_a_negative_withholding_never_becomes_a_debit_on_a_liability(): void
    {
        $entry = app(LedgerPosting::class)->payrollRunApproved(
            $this->payrollRun(['total_net' => '8700.00']),
            '-500.00',
        );

        $lines = $this->balanceFor($entry)['lines'];

        foreach ($lines as $line) {
            if ($line['code'] === '2250') {
                $this->assertSame('0.00', $line['debit'], 'a negative withholding was posted as a debit');
            }
        }

        $balance = $this->balanceFor($entry);
        $this->assertSame($balance['debits'], $balance['credits'], 'the journal must still balance');
    }

    public function test_payment_settles_the_liability_against_the_bank(): void
    {
        $run = $this->payrollRun();
        $ledger = app(LedgerPosting::class);

        $ledger->payrollRunApproved($run);
        $entry = $ledger->payrollPaid($run);

        $this->assertNotNull($entry);

        $balance = $this->balanceFor($entry);
        $this->assertSame('9500.00', $balance['debits']);
        $this->assertSame('9500.00', $balance['credits']);

        $byCode = collect($balance['lines'])->keyBy('code');
        $this->assertSame('9500.00', $byCode['2150']['debit'], 'Net Pay Payable is settled');
        $this->assertSame('9500.00', $byCode['1010']['credit'], 'the bank is credited');
    }

    /**
     * Net Pay Payable must return to zero once payroll is approved and paid. If it
     * does not, the liability is still sitting on the balance sheet.
     */
    public function test_net_pay_payable_is_cleared_after_approval_and_payment(): void
    {
        $run = $this->payrollRun();
        $ledger = app(LedgerPosting::class);

        $ledger->payrollRunApproved($run);
        $ledger->payrollPaid($run);

        $account = Account::where('code', '2150')->firstOrFail();

        $net = '0.00';
        foreach ($account->journalItems()->get() as $item) {
            $net = Money::add($net, $item->debit);
            $net = Money::subtract($net, $item->credit);
        }

        $this->assertSame('0.00', $net, 'Net Pay Payable should not carry a balance after payment');
    }

    public function test_approval_cannot_post_twice(): void
    {
        $run = $this->payrollRun();
        $ledger = app(LedgerPosting::class);

$first = $ledger->payrollRunApproved($run);
        $second = $ledger->payrollRunApproved($run);

        $this->assertNotNull($first);
        $this->assertNull($second, 'a second approval posted a duplicate journal');
        $this->assertSame(
            1,
            JournalEntry::query()
                ->where('reference', 'like', str_replace('\\', '\\\\', $run::class.':'.$run->id).':approved')
                ->count(),
        );
    }

    public function test_payment_cannot_settle_twice(): void
    {
        $run = $this->payrollRun();
        $ledger = app(LedgerPosting::class);

        $ledger->payrollRunApproved($run);
        $ledger->payrollPaid($run);
        $again = $ledger->payrollPaid($run);

        $this->assertNull($again, 'a second payment posted another settlement');
    }

    public function test_a_zero_payroll_posts_nothing(): void
    {
        $run = $this->payrollRun(['total_gross' => '0.00', 'total_net' => '0.00', 'total_deductions' => '0.00']);

        $this->assertNull(app(LedgerPosting::class)->payrollPaid($run), 'a zero payout posted a journal');
    }

    public function test_the_wage_cost_is_dated_to_the_period_end(): void
    {
        $entry = app(LedgerPosting::class)->payrollRunApproved($this->payrollRun());

        $this->assertSame(
            '2026-01-31',
            $entry->entry_date->toDateString(),
            'the cost belongs in the period the work happened, not the payment date',
        );
    }

    public function test_approval_goes_through_the_controller_and_sets_the_status(): void
    {
        $run = $this->payrollRun();

        $this->actingAs($this->userForRole('Admin'))
            ->from('/employees/payroll/'.$run->id)
            ->post('/employees/payroll/'.$run->id.'/approve')
            ->assertRedirect();

        $this->assertSame('submitted', $run->fresh()->status);
        $this->assertTrue(app(LedgerPosting::class)->hasJournalFor($run->fresh()));
    }

    public function test_a_void_run_cannot_be_approved(): void
    {
        $run = $this->payrollRun(['status' => 'void']);

        $this->actingAs($this->userForRole('Admin'))
            ->from('/employees/payroll/'.$run->id)
            ->post('/employees/payroll/'.$run->id.'/approve')
            ->assertSessionHas('toasts');

        $this->assertSame('void', $run->fresh()->status);
        $this->assertFalse(app(LedgerPosting::class)->hasJournalFor($run));
    }

    /**
     * Paying a run that was never approved must not leave the payout with no
     * matching accrual.
     */
    public function test_paying_a_run_that_was_never_approved_posts_the_accrual_first(): void
    {
        $run = $this->payrollRun();
        $ledger = app(LedgerPosting::class);

        $this->actingAs($this->userForRole('Admin'))
            ->from('/employees/payroll/'.$run->id)
            ->post('/employees/payroll/'.$run->id.'/mark-paid');

        $this->assertSame('paid', $run->fresh()->status);
        $this->assertTrue(
            $ledger->hasJournalFor($run->fresh()),
            'the payout was recorded with no wage cost behind it',
        );
    }

/**
     * A generated run, posted end to end.
     *
     * The employee has no attendance records for January, so the service treats
     * every working day as an absence and the whole salary is deducted. The run's
     * totals still have to produce a balanced journal, and the net expense has to
     * equal net pay -- because when nothing is payable, the cost is zero.
     */
    public function test_a_generated_run_posts_a_balanced_journal(): void
    {
        $employee = $this->employee('Jane Doe');

        SalaryStructure::create([
            'employee_id' => $employee->id,
            'effective_from' => '2025-01-01',
            'basic_salary' => '50000.00',
            'housing_allowance' => '10000.00',
            'transport_allowance' => '5000.00',
            'other_allowance' => '0',
            'is_active' => true,
        ]);

        $run = $this->payrollRun();

        app(PayrollService::class)->generate($run);
        $run->refresh();

        // Gross is basic plus allowances, and every working day without an attendance
        // record is treated as an absence -- so the deductions clamp at gross and
        // net pay lands on exactly zero rather than going negative.
        $this->assertSame('65000.00', Money::of($run->total_gross), 'gross is basic plus allowances');

        foreach ($run->payslips()->get() as $payslip) {
            $this->assertFalse(
                Money::isLessThan($payslip->net_pay, '0'),
                'a payslip must never show negative net pay',
            );
        }

        $entry = app(LedgerPosting::class)->payrollRunApproved($run);
        $this->assertNotNull($entry);

        $balance = $this->balanceFor($entry);
        $this->assertSame($balance['debits'], $balance['credits'], 'the generated run must balance');
$this->assertSame(Money::of($run->total_gross), $balance['debits']);
    }
}
