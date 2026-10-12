<?php

namespace App\Http\Controllers\Employees;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Services\Accounting\LedgerPosting;
use App\Services\NotificationService;
use App\Services\PayrollService;
use App\Support\ExportsCsv;
use App\Support\ExportsJson;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PayrollRunController extends Controller
{
    use ExportsCsv;
    use ExportsJson;

    public function __construct(private readonly PayrollService $payroll) {}

    public function index(Request $request): View
    {
        $runs = PayrollRun::query()
            ->withCount('payslips')
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->orderByDesc('period_end')
            ->paginate(15)
            ->withQueryString();

        return view('employees.payroll.index', compact('runs'));
    }

    public function create(): View
    {
        return view('employees.payroll.create', [
            'employees' => Employee::query()->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'employee_code']),
        ]);
    }

    /**
     * Record the wage cost and what is owed, at the moment the run is approved.
     *
     * The posting is keyed to the run, so LedgerPosting makes it idempotent: an
     * approval that is retried or double-clicked cannot double the expense.
     */
    public function approve(PayrollRun $run): RedirectResponse
    {
        if ($run->status === 'void') {
            return back()->with('toasts', [['type' => 'danger', 'message' => 'Void payroll runs cannot be approved.']]);
        }

        if ($run->status === 'paid') {
            return back()->with('toasts', [['type' => 'info', 'message' => 'This run is already paid.']]);
        }

        try {
            $run->update(['status' => 'submitted']);

            $entry = app(LedgerPosting::class)->payrollRunApproved(
                $run,
                $this->withheldFor($run),
                $run->period_end?->toDateString(),
            );
        } catch (\RuntimeException $e) {
            return back()->with('toasts', [['type' => 'danger', 'message' => $e->getMessage()]]);
        }

        return back()->with('toasts', [[
            'type' => 'success',
            'message' => $entry
                ? "{$run->number} approved and posted to the ledger."
                : "{$run->number} approved.",
        ]]);
    }

    /**
     * Employee tax withheld across the run's payslips.
     *
     * Summed from the payslips' own `statutory_deductions`, which the payroll
     * service computes from the employee's configured rates.
     *
     * This used to be `gross - net`. That was wrong, and wrong in a way that
     * reached the balance sheet: gross less net also contains lateness and absence,
     * which were never tax. Posting the whole difference to Payroll Tax Payable
     * made the company owe an authority money it did not owe, and left the wage
     * expense overstated because the attendance element was sitting in a tax
     * liability instead of coming back out of the cost.
     *
     * A run whose structures have no statutory rates configured now withholds
     * nothing, and says so -- an explicit zero rather than an invented liability.
     */
    private function withheldFor(PayrollRun $run): string
    {
        return Money::of($run->payslips()->sum('statutory_deductions'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'currency' => ['required', 'string', 'max:8'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'employee_ids' => ['nullable', 'array'],
            'employee_ids.*' => ['integer', Rule::exists('employees', 'id')],
        ]);

        $run = PayrollRun::create([
            'number' => next_document_number('payroll_run', 'PR'),
            'period_start' => $data['period_start'],
            'period_end' => $data['period_end'],
            'status' => 'draft',
            'total_gross' => '0.00',
            'total_deductions' => '0.00',
            'total_net' => '0.00',
            'currency' => $data['currency'],
            'prepared_by' => auth()->id(),
            'notes' => $data['notes'] ?? null,
        ]);

        $employees = null;
        if (! empty($data['employee_ids'] ?? null)) {
            $employees = Employee::whereIn('id', $data['employee_ids'] ?? null)->get();
        }

        $this->payroll->generate($run, $employees);

        return redirect()->route('employees.payroll.show', $run)
            ->with('toasts', [['type' => 'success', 'message' => "Payroll {$run->number} generated with {$run->payslips()->count()} payslips."]]);
    }

    public function show(PayrollRun $run): View
    {
        $run->load(['preparer', 'payslips.employee.department']);

        return view('employees.payroll.show', compact('run'));
    }

    /**
     * A payslip, as a PDF, for one employee in one run.
     *
     * Payslips existed only as rows in the payroll run table, which is a management
     * view: four figures, and no way to hand a document to anyone. The employee is
     * entitled to an itemised statement, and the deductions are broken out for a
     * reason -- attendance and statutory withholding are different things, and one
     * of them never belonged to the employee at all.
     *
     * The payslip is bound through its run, so a payslip id from another run cannot
     * be reached by guessing the URL.
     */
    public function payslipPdf(PayrollRun $run, Payslip $payslip): Response
    {
        abort_unless((int) $payslip->payroll_run_id === (int) $run->id, 404);

        $payslip->load(['employee.department']);
        $run->loadMissing('preparer');

        $html = view('employees.payroll.payslip-pdf', [
            'run' => $run,
            'payslip' => $payslip,
            'brandColor' => settings('branding.primary_color', '#4f46e5'),

            // An unapproved or draft run is not a final statement of pay, and the
            // document says so on its face rather than implying otherwise.
            'stampColor' => in_array($run->status, ['paid', 'approved'], true) ? '#15803d' : '#b45309',
        ])->render();

        return Pdf::loadHTML($html)
            ->setPaper('a4', 'portrait')
            ->setOptions([
                'isRemoteEnabled' => false,
                'isPhpEnabled' => false,
                'defaultFont' => 'DejaVu Sans',
            ])
            ->download('payslip-'.$run->number.'-'.($payslip->employee?->employee_code ?: $payslip->id).'.pdf');
    }

    /**
     * Regenerate payslips (respecting the original employee scope).
     */
    public function generate(PayrollRun $run): RedirectResponse
    {
        if ($run->status === 'void') {
            return back()->with('toasts', [['type' => 'danger', 'message' => 'Void payroll runs cannot be regenerated.']]);
        }

        try {
            $this->payroll->generate($run);
        } catch (\RuntimeException $e) {
            return back()->with('toasts', [['type' => 'danger', 'message' => $e->getMessage()]]);
        }

        return back()->with('toasts', [['type' => 'success', 'message' => 'Payslips regenerated.']]);
    }

    public function markPaid(PayrollRun $run): RedirectResponse
    {
        if ($run->status === 'void') {
            return back()->with('toasts', [['type' => 'danger', 'message' => 'Void payroll runs cannot be marked paid.']]);
        }

        $alreadyPaid = $run->status === 'paid';

        DB::transaction(function () use ($run): void {
            $run = PayrollRun::query()->lockForUpdate()->findOrFail($run->id);
            if ($run->status === 'void') {
                throw new \RuntimeException('Void payroll runs cannot be marked paid.');
            }
            if ($run->status === 'paid') {
                return;
            }

            /**
             * Settle net pay against the liability created at approval.
             *
             * LedgerPosting is idempotent on the run, so paying twice cannot debit
             * Net Pay Payable twice. If approval never posted -- a deployment with an
             * incomplete chart of accounts, or a run approved before this wiring
             * existed -- there is no liability to settle, so the approval is posted
             * here rather than leaving a payroll payout with no matching accrual.
             */
            $ledger = app(LedgerPosting::class);

            if (! $ledger->hasJournalFor($run)) {
                $ledger->payrollRunApproved($run->fresh(), null, $run->period_end?->toDateString());
            }

            $ledger->payrollPaid($run->fresh());

            $run->update(['status' => 'paid', 'paid_at' => now()]);
        });

        if (! $alreadyPaid) {
            app(NotificationService::class)->notifyStaff(
                'employees.payroll_runs.view',
                'Payroll run paid',
                "Payroll {$run->number} for the period ending {$run->period_end->format('d M Y')} was paid out (".money($run->total_net, $run->currency).' net).',
                'success',
                route('employees.payroll.show', $run),
                auth()->id(),
            );
        }

        return back()->with('toasts', [['type' => 'success', 'message' => "{$run->number} marked as paid."]]);
    }

    public function destroy(PayrollRun $run): RedirectResponse
    {
        $run->delete();

        return redirect()->route('employees.payroll.index')
            ->with('toasts', [['type' => 'success', 'message' => "{$run->number} deleted."]]);
    }

    public function export(Request $request, PayrollRun $run): StreamedResponse
    {
        $rows = $run->payslips()->with('employee')->get()->map(fn (Payslip $p) => [
            'code' => $p->employee?->employee_code,
            'employee' => $p->employee?->fullName(),
            'basic' => $p->basic_salary,
            'allowances' => $p->allowances,
            'gross' => $p->gross_pay,
            'present' => $p->days_present,
            'late' => $p->days_late,
            'short_leave' => $p->days_short_leave,
            'half_day' => $p->days_half_day,
            'absent' => $p->days_absent,
            'deductions' => $p->total_deductions,
            'net' => $p->net_pay,
        ]);

        $useJson = $request->query('format') === 'json';

        return $useJson
            ? $this->streamJson($run->number.'.json', $rows)
            : $this->streamCsv($run->number.'.csv', ['Code', 'Employee', 'Basic', 'Allowances', 'Gross', 'Present', 'Late', 'Short leave', 'Half day', 'Absent', 'Deductions', 'Net'], $rows->values());
    }
}
