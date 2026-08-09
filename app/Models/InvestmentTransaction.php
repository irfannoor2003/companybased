<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class InvestmentTransaction extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $auditModule = 'investments';

    protected $fillable = [
        'investment_id', 'transaction_date', 'type', 'quantity',
        'unit_price', 'fees', 'total', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'quantity' => 'decimal:6',
            'unit_price' => 'decimal:2',
            'fees' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public static function typeOptions(): array
    {
        return ['buy', 'sell'];
    }

    public function investment(): BelongsTo
    {
        return $this->belongsTo(Investment::class);
    }
}