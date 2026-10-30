<?php

namespace Tests\Feature;

use App\Models\DocumentTemplate;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Support\PdfLayout;
use Tests\SeedsDatabase;
use Tests\Support\PdfText;
use Tests\TestCase;

/**
 * An employee can be given a payslip.
 *
 * Payslips existed only as rows in the payroll run table, which is a management
 * view: four figures and no way to hand a document to anyone. The run's own table
 * did not even itemise the deductions -- it showed gross, attendance and net, so
 * the visible columns did not add up to the net figure on the same row once
 * statutory withholding was being deducted.
 *
 * The PDF exists to make the breakdown legible, so that is what is asserted: that
 * attendance and statutory appear separately and that the figures reconcile.
 */
class PayslipPdfTest extends TestCase
{
    use SeedsDatabase;

    private ?\App\Models\User $admin = null;

    private function admin(): \App\Models\User
    {
        return $this->admin ??= $this->userForRole('Admin');
    }

    private function employee(): Employee
    {
        return Employee::create([
            'employee_code' => 'EMP-1042',
            'first_name' => 'Sana',
            'last_name' => 'Rahman',
            'email' => 'sana@example.test',
            'job_title' => 'Accounts Clerk',
            'employment_status' => 'active',
            'date_hired' => '2024-03-01',
        ]);
    }

    private function payrollRun(string $status = 'approved'): PayrollRun
    {
        return PayrollRun::create([
            'number' => 'PR-2026-10',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'status' => $status,
            'total_gross' => '50000.00',
            'total_deductions' => '9000.00',
            'total_net' => '41000.00',
            'currency' => 'PKR',
            'exchange_rate' => 1,
            'prepared_by' => $this->admin()->id,
        ]);
    }

    /**
     * gross - attendance - statutory = net, which is what the document claims.
     */
    private function payslip(PayrollRun $run, Employee $employee, array $attributes = []): Payslip
    {
        return Payslip::create(array_merge([
            'payroll_run_id' => $run->id,
            'employee_id' => $employee->id,
            'basic_salary' => '45000.00',
            'allowances' => '5000.00',
            'gross_pay' => '50000.00',
            'days_present' => 21,
            'days_late' => 1,
            'days_short_leave' => 0,
            'days_half_day' => 1,
            'days_absent' => 1,
            'attendance_deductions' => '4000.00',
            'statutory_deductions' => '5000.00',
            'total_deductions' => '9000.00',
            'net_pay' => '41000.00',
        ], $attributes));
    }

    private function pdf(PayrollRun $run, Payslip $payslip): string
    {
        return $this->actingAs($this->admin())
            ->get('/employees/payroll/'.$run->id.'/payslips/'.$payslip->id)
            ->assertOk()
            ->getContent();
    }

    public function test_the_payslip_route_returns_a_pdf(): void
    {
        $run = $this->payrollRun();
        $employee = $this->employee();
        $payslip = $this->payslip($run, $employee);

        $response = $this->actingAs($this->admin())
            ->get('/employees/payroll/'.$run->id.'/payslips/'.$payslip->id)
            ->assertOk();

        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('content-type'));
    }

    public function test_the_payslip_identifies_the_employee_and_the_period(): void
    {
        $run = $this->payrollRun();
        $employee = $this->employee();
        $text = PdfText::all($this->pdf($run, $this->payslip($run, $employee)));

        $this->assertStringContainsString('PAYSLIP', $text);
        $this->assertStringContainsString('Sana Rahman', $text, 'the employee must be named on their payslip');
        $this->assertStringContainsString('EMP-1042', $text, 'the employee code must print');
        $this->assertStringContainsString('Accounts Clerk', $text);
        $this->assertStringContainsString('PR-2026-10', $text, 'the run number must print');
        $this->assertStringContainsString('01/10/2026', $text, 'the period start must print');
        $this->assertStringContainsString('31/10/2026', $text, 'the period end must print');
    }

    /**
     * The substance of the document: the two deductions are separate lines.
     *
     * Statutory withholding is money withheld by law and never belonged to the
     * employee. Presented as one "deductions" figure alongside absence, it reads as
     * a penalty, and it is the first thing anyone queries.
     */
    public function test_attendance_and_statutory_deductions_are_itemised_separately(): void
    {
        $run = $this->payrollRun();
        $employee = $this->employee();
        $text = PdfText::all($this->pdf($run, $this->payslip($run, $employee)));

        /**
         * Headings are asserted in upper case because the template sets them with
         * `text-transform: uppercase`, which DomPDF bakes into the glyphs rather
         * than leaving it to CSS. They are not missing from the page; they are
         * printed as EARNINGS.
         */
        $this->assertStringContainsString('EARNINGS', $text);
        $this->assertStringContainsString('Basic salary', $text);
        $this->assertStringContainsString('Allowances', $text);
        $this->assertStringContainsString('Gross pay', $text);

        $this->assertStringContainsString('DEDUCTIONS', $text);
        $this->assertStringContainsString('Attendance', $text);
        $this->assertStringContainsString('Statutory withholding', $text);

        $this->assertStringContainsString('NET PAY', $text);
        $this->assertStringContainsString('41,000.00', $text, 'the net figure must print');
    }

    /**
     * The figures on the face of the payslip have to reconcile, because that is the
     * whole purpose of itemising them: 50,000 - 4,000 - 5,000 = 41,000.
     */
    public function test_the_printed_figures_reconcile(): void
    {
        $run = $this->payrollRun();
        $employee = $this->employee();

        $payslip = $this->payslip($run, $employee);
        $text = PdfText::all($this->pdf($run, $payslip));

        /**
         * Thousands separators, because that is how the figures are printed and how
         * they must be read. Asserting the bare digits would pass only if the
         * document stopped formatting its own money.
         */
        foreach (['45,000.00', '5,000.00', '4,000.00', '9,000.00', '41,000.00'] as $figure) {
            $this->assertStringContainsString($figure, $text, $figure.' must appear on the payslip');
        }

        $this->assertEqualsWithDelta(
            (float) $payslip->gross_pay - (float) $payslip->attendance_deductions - (float) $payslip->statutory_deductions,
            (float) $payslip->net_pay,
            0.01,
            'the payslip data does not reconcile, so the printed document would not either',
        );
    }

    /**
     * Attendance detail is what makes an attendance deduction explicable.
     */
    public function test_the_attendance_breakdown_prints(): void
    {
        $run = $this->payrollRun();
        $employee = $this->employee();
        $text = PdfText::all($this->pdf($run, $this->payslip($run, $employee)));

        $this->assertStringContainsString('PRESENT', $text);
        $this->assertStringContainsString('LATE', $text);
        $this->assertStringContainsString('HALF DAY', $text);
        $this->assertStringContainsString('ABSENT', $text);
    }

    /**
     * A run that has not been approved is not a final statement of pay. The document
     * carries its status so it cannot be mistaken for one.
     */
    public function test_an_unapproved_run_is_stamped_on_the_document(): void
    {
        $run = $this->payrollRun('draft');
        $employee = $this->employee();
        $text = PdfText::all($this->pdf($run, $this->payslip($run, $employee)));

        $this->assertStringContainsString('DRAFT', $text, 'an unapproved payslip must say so');
    }

    public function test_an_approved_run_carries_no_provisional_stamp(): void
    {
        $run = $this->payrollRun('approved');
        $employee = $this->employee();
        $text = PdfText::all($this->pdf($run, $this->payslip($run, $employee)));

        $this->assertStringNotContainsString('DRAFT', $text);
        $this->assertStringContainsString('41,000.00', $text);
    }

    /**
     * A payslip belongs to a run. Pairing a payslip id with a different run's id in
     * the URL must not produce that run's letterhead over another employee's pay.
     */
    public function test_a_payslip_cannot_be_reached_through_another_run(): void
    {
        $runA = $this->payrollRun();
        $runB = PayrollRun::create([
            'number' => 'PR-2026-09',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'status' => 'approved',
            'total_gross' => '0', 'total_deductions' => '0', 'total_net' => '0',
            'currency' => 'PKR', 'exchange_rate' => 1,
            'prepared_by' => $this->admin()->id,
        ]);

        $employee = $this->employee();
        $payslip = $this->payslip($runA, $employee);

        $this->actingAs($this->admin())
            ->get('/employees/payroll/'.$runB->id.'/payslips/'.$payslip->id)
            ->assertNotFound();
    }

    public function test_the_payslip_requires_the_payroll_permission(): void
    {
        $run = $this->payrollRun();
        $employee = $this->employee();
        $payslip = $this->payslip($run, $employee);

        $this->actingAs($this->userForRole('Employee'))
            ->get('/employees/payroll/'.$run->id.'/payslips/'.$payslip->id)
            ->assertForbidden();
    }

    /**
     * The payslip follows the INVOICE design's colours and operator stylesheet.
     *
     * A payslip has no template row of its own -- the schema has no type for it
     * and nobody would find a second settings screen for one document. What it
     * CAN do is answer the invoice default: the brand colour the documents
     * already print in, and the operator's stylesheet that styles the whole
     * suite. Asserted at the view layer with the data the controller resolves,
     * because the route returns PDF bytes and the operator's CSS is a style
     * rule, not text a DomPDF page carries; the PdfLayout resolution itself is
     * pinned in PdfLayoutTest and InvoicePdfTemplateTest.
     */
    public function test_the_payslip_takes_the_invoice_designs_colour_and_styling(): void
    {
        $template = DocumentTemplate::query()
            ->where('type', 'invoice')
            ->where('is_default', true)
            ->firstOrFail();

        $oldColors = $template->colors;
        $oldCss = $template->css;

        $template->update([
            'colors' => ['primary' => '#0f766e', 'accent' => '#0f766e', 'text' => '#1f2937'],
            'css' => '.payslip-operator-marker { color: #0f766e; }',
        ]);

        try {
            $run = $this->payrollRun('approved')->loadMissing('preparer');
            $payslip = $this->payslip($run, $this->employee());

            $layout = PdfLayout::forType('invoice');

            $html = view('employees.payroll.payslip-pdf', [
                'run' => $run,
                'payslip' => $payslip,
                'brandColor' => $layout->color('primary', settings('branding.primary_color', '#4f46e5')),
                'customCss' => $layout->templateCss(),
                'stampColor' => in_array($run->status, ['paid', 'approved'], true) ? '#15803d' : '#b45309',
            ])->render();

            $this->assertStringContainsString(
                '#0f766e',
                $html,
                'the invoice template colour must reach the payslip',
            );

            $this->assertStringContainsString(
                '.payslip-operator-marker',
                $html,
                'the operator stylesheet must style the payslip too',
            );
        } finally {
            $template->update(['colors' => $oldColors, 'css' => $oldCss]);
        }
    }
}
