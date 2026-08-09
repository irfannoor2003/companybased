<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PosShift extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $auditModule = 'pos';

    protected $fillable = [
        'shift_number', 'opened_by', 'opened_at', 'opening_cash', 'status',
        'closed_by', 'closed_at', 'expected_cash', 'counted_cash', 'variance', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_cash' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'counted_cash' => 'decimal:2',
            'variance' => 'decimal:2',
        ];
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(PosSale::class, 'shift_id');
    }

    public function salesTotal(): float
    {
        return round((float) $this->sales()->where('status', 'completed')->sum('total'), 2);
    }

    public static function statusOptions(): array
    {
        return ['open', 'closed'];
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}