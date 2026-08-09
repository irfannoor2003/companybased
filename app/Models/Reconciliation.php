<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Reconciliation extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $fillable = [
        'number', 'bank_account_id', 'statement_date', 'opening_balance',
        'statement_ending_balance', 'status', 'notes',
    ];

    protected $auditModule = 'banking';

    protected function casts(): array
    {
        return [
            'statement_date' => 'date',
            'opening_balance' => 'decimal:2',
            'statement_ending_balance' => 'decimal:2',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReconciliationItem::class, 'reconciliation_id');
    }

    public static function statusOptions(): array
    {
        return ['draft', 'completed', 'cancelled'];
    }

    /**
     * Computed book balance = opening balance plus the cleared lines.
     */
    public function bookBalance(): float
    {
        $balance = (float) $this->opening_balance;

        foreach ($this->items->loadMissing('transaction') as $item) {
            if ($item->is_cleared && $item->transaction) {
                $balance += $item->transaction->signedAmount();
            }
        }

        return round($balance, 2);
    }

    public function difference(): float
    {
        return round((float) $this->statement_ending_balance - $this->bookBalance(), 2);
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }
}
