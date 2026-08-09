<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BankAccount extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $fillable = [
        'name', 'account_number', 'bank_name', 'branch', 'account_type',
        'currency', 'opening_balance', 'is_active', 'notes',
    ];

    protected $auditModule = 'banking';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'opening_balance' => 'decimal:2',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class, 'bank_account_id');
    }

    public function transfersFrom(): HasMany
    {
        return $this->hasMany(BankTransfer::class, 'from_account_id');
    }

    public function transfersTo(): HasMany
    {
        return $this->hasMany(BankTransfer::class, 'to_account_id');
    }

    public function reconciliations(): HasMany
    {
        return $this->hasMany(Reconciliation::class, 'bank_account_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('bank_name', 'like', "%{$term}%")
                ->orWhere('account_number', 'like', "%{$term}%");
        });
    }

    public static function typeOptions(): array
    {
        return ['checking', 'savings', 'cash'];
    }

    /**
     * Available balance = opening balance plus every posted transaction.
     * Deposits/transfers-in add; withdrawals/transfers-out deduct.
     */
    public function balance(): float
    {
        $in = (float) $this->transactions()->whereIn('type', ['deposit', 'transfer_in'])->sum('amount');
        $out = (float) $this->transactions()->whereIn('type', ['withdrawal', 'transfer_out'])->sum('amount');

        return round((float) $this->opening_balance + $in - $out, 2);
    }

    public function clearedBalance(): float
    {
        $in = (float) $this->transactions()->whereIn('type', ['deposit', 'transfer_in'])->where('is_reconciled', true)->sum('amount');
        $out = (float) $this->transactions()->whereIn('type', ['withdrawal', 'transfer_out'])->where('is_reconciled', true)->sum('amount');

        return round((float) $this->opening_balance + $in - $out, 2);
    }

    public function balanceBefore(string $date): float
    {
        $in = (float) $this->transactions()->where('transaction_date', '<', $date)
            ->whereIn('type', ['deposit', 'transfer_in'])->sum('amount');
        $out = (float) $this->transactions()->where('transaction_date', '<', $date)
            ->whereIn('type', ['withdrawal', 'transfer_out'])->sum('amount');

        return round((float) $this->opening_balance + $in - $out, 2);
    }
}
