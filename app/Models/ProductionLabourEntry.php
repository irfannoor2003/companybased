<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Time worked on a production order.
 *
 * The link between attendance and manufacturing that did not exist before: hours
 * recorded against an employee, attributed to the job that consumed them, so
 * factory labour can be capitalised into the goods it made instead of being
 * expensed in one undifferentiated monthly total.
 *
 * labour_cost and hourly_rate are stored, never derived at posting time. A pay
 * rise must not restate work already done and already posted.
 */
class ProductionLabourEntry extends Model
{
    protected $fillable = [
        'production_order_id', 'employee_id', 'minutes', 'hourly_rate',
        'labour_cost', 'work_date', 'absorbed_at', 'note',
    ];

    protected function casts(): array
    {
        return [
            'minutes' => 'integer',
            'hourly_rate' => 'decimal:4',
            'labour_cost' => 'decimal:2',
            'work_date' => 'date',
            'absorbed_at' => 'datetime',
        ];
    }

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(InventoryProductionOrder::class, 'production_order_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Hours as a decimal, for rate multiplication.
     */
    public function hours(): float
    {
        return round($this->minutes / 60, 4);
    }

    /**
     * Not yet moved into work in progress.
     */
    public function scopeUnabsorbed($query)
    {
        return $query->whereNull('absorbed_at');
    }

    /**
     * Cost this entry contributes, from the snapshot rate rather than the
     * employee's current salary.
     */
    public function computedCost(): string
    {
        return number_format($this->minutes / 60 * (float) $this->hourly_rate, 2, '.', '');
    }
}