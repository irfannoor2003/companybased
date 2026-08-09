<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PosReconciliation extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $auditModule = 'pos';

    protected $fillable = [
        'shift_id', 'reconciled_by', 'reconciled_at', 'opening_cash',
        'sales_total', 'expected_cash', 'counted_cash', 'variance', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'reconciled_at' => 'datetime',
            'opening_cash' => 'decimal:2',
            'sales_total' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'counted_cash' => 'decimal:2',
            'variance' => 'decimal:2',
        ];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(PosShift::class, 'shift_id');
    }

    public function reconciler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }
}