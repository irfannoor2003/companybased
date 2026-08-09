<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class BankTransaction extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $fillable = [
        'bank_account_id', 'number', 'transaction_date', 'type', 'amount',
        'counterparty', 'description', 'reference', 'is_reconciled',
        'reconciled_at', 'reference_type', 'reference_id',
    ];

    protected $auditModule = 'banking';

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'amount' => 'decimal:2',
            'is_reconciled' => 'boolean',
            'reconciled_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(BankTransfer::class, 'reference_id', 'id')->where('reference_type', BankTransfer::class);
    }

    public static function typeOptions(): array
    {
        return ['deposit', 'withdrawal'];
    }

    public function isCredit(): bool
    {
        return in_array($this->type, ['deposit', 'transfer_in'], true);
    }

    public function isDebit(): bool
    {
        return in_array($this->type, ['withdrawal', 'transfer_out'], true);
    }

    public function signedAmount(): float
    {
        return $this->isDebit() ? -1 * (float) $this->amount : (float) $this->amount;
    }
}
