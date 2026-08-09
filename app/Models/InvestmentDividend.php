<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class InvestmentDividend extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $auditModule = 'investments';

    protected $fillable = [
        'investment_id', 'dividend_date', 'amount', 'currency', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'dividend_date' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    public function investment(): BelongsTo
    {
        return $this->belongsTo(Investment::class);
    }
}