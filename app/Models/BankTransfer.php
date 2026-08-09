<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BankTransfer extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $fillable = [
        'number', 'transfer_date', 'from_account_id', 'to_account_id',
        'amount', 'description', 'status', 'completed_at',
    ];

    protected $auditModule = 'banking';

    protected function casts(): array
    {
        return [
            'transfer_date' => 'date',
            'amount' => 'decimal:2',
            'completed_at' => 'datetime',
        ];
    }

    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'from_account_id');
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'to_account_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class, 'reference_id', 'id')->where('reference_type', BankTransfer::class);
    }

    public static function statusOptions(): array
    {
        return ['draft', 'completed', 'cancelled'];
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }
}
