<?php

namespace App\Support;

/**
 * Builds the shared document payload for a payslip.
 *
 * A payslip used to print its own HTML and borrow only the invoice's colour and
 * custom stylesheet, so it could never be restyled from Settings -> Templates and
 * looked unlike every other document the company prints. It now renders through
 * the same frame: the run supplies the title and period, the employee and figures
 * become meta, and the itemised body is a dedicated block inside the canvas.
 */
class PayslipDocument
{
    /**
     * @param  \App\Models\PayrollRun  $run
     * @param  \App\Models\Payslip  $payslip
     * @return array<string,mixed>
     */
    public static function payload($run, $payslip): array
    {
        $currency = $run->currency ?: settings('company.currency') ?: 'USD';

        return DocumentData::make('payslip', [
            'doc' => $payslip,
            'title' => 'Payslip',
            'number' => $run->number,
            'currency' => $currency,
            'dateLabel' => 'Period',
            'dateValue' => $run->period_start?->format('d/m/Y').' - '.$run->period_end?->format('d/m/Y'),
            'headlineLabel' => 'Net pay',
            'headlineValue' => money((float) $payslip->net_pay, $currency),
            'meta' => [
                ['label' => 'Employee', 'value' => $payslip->employee?->fullName(), 'show' => true],
                ['label' => 'Employee code', 'value' => $payslip->employee?->employee_code, 'show' => true],
                ['label' => 'Designation', 'value' => $payslip->employee?->job_title, 'show' => true],
                ['label' => 'Department', 'value' => $payslip->employee?->department?->name, 'show' => true],
                ['label' => 'Date joined', 'value' => $payslip->employee?->date_hired?->format('d/m/Y'), 'show' => true],
            ],
            'body' => 'employees.payroll.payslip-body',
            'run' => $run,
            'payslip' => $payslip,
            'blockReady' => [
                'items' => false,
                'table' => false,
                'totals' => false,
                'receipt' => false,
                'bank' => false,
                'terms' => false,
                'signature' => false,
            ],
        ]);
    }
}
