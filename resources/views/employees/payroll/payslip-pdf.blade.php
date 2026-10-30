<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Payslip {{ $run->number }} - {{ $payslip->employee?->fullName() }}</title>

    {{--
        A payslip, as handed to an employee.

        Payslips existed only as rows in the payroll run table, which is a
        management view: it shows four figures and cannot be given to anyone. An
        employee is entitled to a document that itemises what they were paid, what
        was deducted and why, so the deductions are broken out rather than summed.

        The deduction breakdown is the substance of this document. Attendance and
        statutory deductions are separate because they are different things: one is
        for being absent, the other is withheld by law and never belonged to the
        employee. Collapsing them into a single "deductions" figure is what makes a
        payslip unusable when someone queries a shortfall, and it is also what
        briefly hid the statutory line from this system when payroll was first
        implemented.

        DomPDF notes: no Blade layout, no components, no Tailwind, no CSS custom
        properties (it does not resolve them). Widths are percentages, because
        DomPDF ignores millimetres on <td>.
    --}}
    <style>
        * { box-sizing: border-box; }

        body {
            font-family: 'DejaVu Sans', Helvetica, Arial, sans-serif;
            color: #1f2937;
            font-size: 10px;
            line-height: 1.45;
            margin: 0;
            padding: 0;
        }

        .page { padding: 28px 34px; }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 3px solid {{ $brandColor }};
            padding-bottom: 12px;
        }
        .brand-name { font-size: 17px; font-weight: bold; color: {{ $brandColor }}; }
        .brand-meta { font-size: 8.5px; color: #6b7280; margin-top: 2px; }
        .doc-title { text-align: right; }
        .doc-title h1 { font-size: 19px; font-weight: bold; margin: 0; color: #111827; letter-spacing: 0.06em; }
        .doc-title p { margin: 2px 0 0; font-size: 9px; color: #6b7280; }

        table { width: 100%; border-collapse: collapse; }
        table.meta { margin: 14px 0; background: #f9fafb; border: 1px solid #e5e7eb; padding: 8px 10px; }
        table.meta td { font-size: 9px; padding: 1.5px 0; vertical-align: top; }
        table.meta td.k { width: 22%; color: #6b7280; }
        table.meta td.v { width: 28%; font-weight: bold; }

        h2 {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: #6b7280;
            margin: 14px 0 5px;
            border-bottom: 1px solid #e5e7eb;
            padding-bottom: 3px;
        }

        table.figures td { padding: 4px 6px; font-size: 9.5px; }
        table.figures td.label { color: #374151; }
        table.figures td.value { text-align: right; font-weight: bold; white-space: nowrap; }
        table.figures tr.section td {
            background: #f3f4f6;
            font-weight: bold;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #374151;
        }
        table.figures tr.total td {
            border-top: 1px solid {{ $brandColor }};
            color: {{ $brandColor }};
            font-weight: bold;
            font-size: 12px;
            padding: 6px;
        }

        /* Deductions are shown as negative figures because that is how an employee
           reads them: what left the salary, not a positive quantity. */
        table.figures td.value.deduction { color: #b91c1c; }

        table.attendance td { font-size: 9px; padding: 3px 6px; text-align: center; }
        table.attendance th { font-size: 8px; text-transform: uppercase; letter-spacing: 0.06em; color: #6b7280; padding: 3px 6px; border-bottom: 1px solid #e5e7eb; }
        table.attendance td { border-bottom: 1px solid #f3f4f6; }

        .notes { margin-top: 12px; background: #f9fafb; border-left: 3px solid {{ $brandColor }}; padding: 7px 10px; font-size: 9px; }
        .notes p { margin: 0; }

        .footer { margin-top: 16px; border-top: 1px solid #e5e7eb; padding-top: 8px; font-size: 8px; color: #6b7280; }
        .footer table td { vertical-align: top; font-size: 8px; }
        .footer .sign { width: 45%; }
        .sign-rule { border-bottom: 1px solid #d1d5db; height: 20px; margin-bottom: 3px; }

        /* A draft or unapproved run must not look final. */
        .status-stamp {
            display: inline-block;
            border: 2px solid {{ $stampColor }};
            color: {{ $stampColor }};
            font-weight: bold;
            font-size: 9px;
            letter-spacing: 0.12em;
            padding: 2px 7px;
            margin-top: 5px;
        }
    </style>

    @if (filled($customCss))
        {{--
                The invoice template's custom stylesheet, printed LAST so it wins
                the cascade: one operator-written stylesheet styles the whole
                print suite consistently. Payslips have no template of their own,
                so they follow the invoice design's.
            --}}
        <style>{!! $customCss !!}</style>
    @endif
</head>
<body>
    <div class="page">
        <div class="header">
            <div>
                <div class="brand-name">{{ company_name() }}</div>
                @if (filled(settings('company.address')))
                    <div class="brand-meta">{!! nl2br(e(settings('company.address'))) !!}</div>
                @endif
                @if (filled(settings('company.phone')))
                    <div class="brand-meta">Tel: {{ settings('company.phone') }}</div>
                @endif
                @if (filled(settings('company.tax_number')))
                    <div class="brand-meta">Tax No: {{ settings('company.tax_number') }}</div>
                @endif
            </div>
            <div class="doc-title">
                <h1>PAYSLIP</h1>
                <p>{{ $run->number }}</p>
                <p>{{ $run->period_start?->format('d/m/Y') }} — {{ $run->period_end?->format('d/m/Y') }}</p>
                {{-- A run that has not been approved is not a final statement of
                     what was paid, and saying so on the document is the difference
                     between an informative payslip and a disputed one. --}}
                @if ($run->status !== 'paid' && $run->status !== 'approved')
                    <div class="status-stamp">{{ strtoupper(str_replace('_', ' ', $run->status)) }}</div>
                @endif
            </div>
        </div>

        <table class="meta">
            <tr>
                <td class="k">Employee</td>
                <td class="v">{{ $payslip->employee?->fullName() ?: '—' }}</td>
                <td class="k">Employee code</td>
                <td class="v">{{ $payslip->employee?->employee_code ?: '—' }}</td>
            </tr>
            <tr>
                <td class="k">Designation</td>
                <td class="v">{{ $payslip->employee?->job_title ?: '—' }}</td>
                <td class="k">Department</td>
                <td class="v">{{ $payslip->employee?->department?->name ?: '—' }}</td>
            </tr>
            <tr>
                <td class="k">Date joined</td>
                <td class="v">{{ $payslip->employee?->date_hired?->format('d/m/Y') ?: '—' }}</td>
                <td class="k">Currency</td>
                <td class="v">{{ $run->currency ?: '—' }}</td>
            </tr>
        </table>

        <h2>Earnings</h2>
        <table class="figures">
            <tr>
                <td class="label">Basic salary</td>
                <td class="value">{{ number_format((float) $payslip->basic_salary, 2) }}</td>
            </tr>
            <tr>
                <td class="label">Allowances</td>
                <td class="value">{{ number_format((float) $payslip->allowances, 2) }}</td>
            </tr>
            <tr class="total">
                <td class="label">Gross pay</td>
                <td class="value">{{ number_format((float) $payslip->gross_pay, 2) }}</td>
            </tr>
        </table>

        <h2>Attendance</h2>
        <table class="attendance">
            <thead>
                <tr>
                    <th>Present</th>
                    <th>Late</th>
                    <th>Half day</th>
                    <th>Short leave</th>
                    <th>Absent</th>
                </tr>
            </thead>
            <tr>
                <td>{{ (int) $payslip->days_present }}</td>
                <td>{{ (int) $payslip->days_late }}</td>
                <td>{{ (int) $payslip->days_half_day }}</td>
                <td>{{ (int) $payslip->days_short_leave }}</td>
                <td>{{ (int) $payslip->days_absent }}</td>
            </tr>
        </table>

        <h2>Deductions</h2>
        <table class="figures">
            <tr>
                <td class="label">Attendance</td>
                <td class="value deduction">-{{ number_format((float) $payslip->attendance_deductions, 2) }}</td>
            </tr>
            <tr>
                {{-- Statutory withholding, itemised separately from attendance. It is
                     withheld by law and was never part of the employee's pay, so
                     lumping it in with absence makes the figure look like a penalty. --}}
                <td class="label">Statutory withholding (tax and social security)</td>
                <td class="value deduction">-{{ number_format((float) $payslip->statutory_deductions, 2) }}</td>
            </tr>
            <tr class="total">
                <td class="label">Total deductions</td>
                <td class="value">-{{ number_format((float) $payslip->total_deductions, 2) }}</td>
            </tr>
        </table>

        <h2>Net pay</h2>
        <table class="figures">
            <tr class="total">
                <td class="label">Amount paid to employee</td>
                <td class="value">{{ number_format((float) $payslip->net_pay, 2) }}</td>
            </tr>
        </table>

        @if (filled($payslip->notes))
            <div class="notes">
                <p>{{ $payslip->notes }}</p>
            </div>
        @endif

        <div class="footer">
            <table>
                <tr>
                    <td class="sign">
                        <div class="sign-rule"></div>
                        <div style="font-size: 8px; color: #6b7280;">Employee signature</div>
                    </td>
                    <td>
                        <div class="sign-rule"></div>
                        <div style="font-size: 8px; color: #6b7280;">For {{ company_name() }}</div>
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
    </div>
</body>
</html>
