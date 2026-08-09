<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payslip extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $auditModule = 'employees';

    protected $fillable = [
        'payroll_run_id', 'employee_id', 'basic_salary', 'allowances', 'gross_pay',
        'days_present', 'days_late', 'days_short_leave', 'days_half_day', 'days_absent',
        'attendance_deductions', 'total_deductions', 'net_pay', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'basic_salary' => 'decimal:2',
            'allowances' => 'decimal:2',
            'gross_pay' => 'decimal:2',
            'attendance_deductions' => 'decimal:2',
            'total_deductions' => 'decimal:2',
            'net_pay' => 'decimal:2',
        ];
    }

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
