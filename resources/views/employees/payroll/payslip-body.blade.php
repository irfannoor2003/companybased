{{--
    The itemised payslip body, printed INSIDE the shared document frame.

    The letterhead, title, period and net-pay headline are printed by the frame
    from PayslipDocument's payload, exactly as they are on an invoice. What is
    left here is the substance an employee needs: earnings, the attendance
    breakdown, deductions separated by cause, and the net figure. Keeping the
    deduction lines apart is the point of the document -- one is for absence,
    the other is withheld by law and never belonged to the employee.

    Class names are prefixed `ps-` so this body cannot collide with the frame's
    own styles. CSS custom properties are avoided: DomPDF does not resolve them.
--}}
<style>
    .ps-stamp {
        display: inline-block;
        border: 2px solid #b45309;
        color: #b45309;
        font-weight: bold;
        font-size: 9px;
        letter-spacing: 0.12em;
        padding: 2px 7px;
    }
    table.ps-figures { width: 100%; border-collapse: collapse; }
    table.ps-figures td { padding: 4px 6px; font-size: 9.5px; }
    table.ps-figures td.label { color: #374151; }
    table.ps-figures td.value { text-align: right; font-weight: bold; white-space: nowrap; }
    table.ps-figures td.value.deduction { color: #b91c1c; }
    table.ps-figures tr.total td {
        border-top: 1px solid {{ $layout->color('primary', settings('branding.primary_color', '#4f46e5')) }};
        color: {{ $layout->color('primary', settings('branding.primary_color', '#4f46e5')) }};
        font-weight: bold;
        font-size: 12px;
        padding: 6px;
    }
    h2.ps-heading {
        font-size: 10px;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        color: #6b7280;
        margin: 14px 0 5px;
        border-bottom: 1px solid #e5e7eb;
        padding-bottom: 3px;
    }
    table.ps-attendance { width: 100%; border-collapse: collapse; }
    table.ps-attendance th { font-size: 8px; text-transform: uppercase; letter-spacing: 0.06em; color: #6b7280; padding: 3px 6px; border-bottom: 1px solid #e5e7eb; }
    table.ps-attendance td { font-size: 9px; padding: 3px 6px; text-align: center; border-bottom: 1px solid #f3f4f6; }
    .ps-sign-rule { border-bottom: 1px solid #d1d5db; height: 20px; margin-bottom: 3px; }
</style>

@if ($run->status !== 'paid' && $run->status !== 'approved')
    <div class="ps-stamp">{{ strtoupper(str_replace('_', ' ', $run->status)) }}</div>
@endif

<h2 class="ps-heading">Earnings</h2>
<table class="ps-figures">
    <tr><td class="label">Basic salary</td><td class="value">{{ number_format((float) $payslip->basic_salary, 2) }}</td></tr>
    <tr><td class="label">Allowances</td><td class="value">{{ number_format((float) $payslip->allowances, 2) }}</td></tr>
    <tr class="total"><td class="label">Gross pay</td><td class="value">{{ number_format((float) $payslip->gross_pay, 2) }}</td></tr>
</table>

<h2 class="ps-heading">Attendance</h2>
<table class="ps-attendance">
    <thead>
        <tr><th>Present</th><th>Late</th><th>Half day</th><th>Short leave</th><th>Absent</th></tr>
    </thead>
    <tr>
        <td>{{ (int) $payslip->days_present }}</td>
        <td>{{ (int) $payslip->days_late }}</td>
        <td>{{ (int) $payslip->days_half_day }}</td>
        <td>{{ (int) $payslip->days_short_leave }}</td>
        <td>{{ (int) $payslip->days_absent }}</td>
    </tr>
</table>

<h2 class="ps-heading">Deductions</h2>
<table class="ps-figures">
    <tr><td class="label">Attendance</td><td class="value deduction">-{{ number_format((float) $payslip->attendance_deductions, 2) }}</td></tr>
    <tr><td class="label">Statutory withholding (tax and social security)</td><td class="value deduction">-{{ number_format((float) $payslip->statutory_deductions, 2) }}</td></tr>
    <tr class="total"><td class="label">Total deductions</td><td class="value">-{{ number_format((float) $payslip->total_deductions, 2) }}</td></tr>
</table>

<h2 class="ps-heading">Net pay</h2>
<table class="ps-figures">
    <tr class="total"><td class="label">Amount paid to employee</td><td class="value">{{ number_format((float) $payslip->net_pay, 2) }}</td></tr>
</table>

@if (filled($payslip->notes))
    <div class="notes"><p>{{ $payslip->notes }}</p></div>
@endif

<div class="ps-footer" style="margin-top: 16px; border-top: 1px solid #e5e7eb; padding-top: 8px; font-size: 8px; color: #6b7280;">
    <table style="width: 100%; border-collapse: collapse;">
        <tr>
            <td style="width: 45%; vertical-align: top;">
                <div class="ps-sign-rule"></div>
                Employee signature
            </td>
            <td style="vertical-align: top;">
                <div class="ps-sign-rule"></div>
                For {{ company_name() }}
            </td>
        </tr>
    </table>
    <p style="margin: 10px 0 0;">
        This is a computer-generated payslip. The figures above are stated in
        {{ $run->currency ?: 'the run currency' }} and reflect the payroll run
        {{ $run->number }} for the period
        {{ $run->period_start?->format('d/m/Y') }} to {{ $run->period_end?->format('d/m/Y') }}.
        Queries should be raised with the payroll office within 30 days of the pay date.
    </p>
</div>
