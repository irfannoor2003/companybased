<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalaryStructure extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $auditModule = 'employees';

    protected $fillable = [
        'employee_id', 'effective_from', 'basic_salary', 'housing_allowance',
        'transport_allowance', 'other_allowance', 'statutory_tax_percent',
        'statutory_social_percent', 'is_active', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'basic_salary' => 'decimal:2',
            'housing_allowance' => 'decimal:2',
            'transport_allowance' => 'decimal:2',
            'other_allowance' => 'decimal:2',
            // Four places: a rate like 0.1834 must not be rounded to 0.18 and
            // silently under-withhold on every payslip.
            'statutory_tax_percent' => 'decimal:4',
            'statutory_social_percent' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function allowances(): float
    {
        return round(
            (float) $this->housing_allowance
            + (float) $this->transport_allowance
            + (float) $this->other_allowance,
            2,
        );
    }

    public function gross(): float
    {
        return round((float) $this->basic_salary + $this->allowances(), 2);
    }
}
