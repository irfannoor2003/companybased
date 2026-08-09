<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReconciliationItem extends Model
{
    protected $fillable = [
        'reconciliation_id', 'bank_transaction_id', 'is_cleared',
    ];

    protected function casts(): array
    {
        return [
            'is_cleared' => 'boolean',
        ];
    }

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(Reconciliation::class, 'reconciliation_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class, 'bank_transaction_id');
    }
}
