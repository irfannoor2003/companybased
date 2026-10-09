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
        'reconciled_at', 'reference_type', 'reference_id', 'journal_entry_id',
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

    /**
     * The journal this transaction posted, when it has one.
     *
     * Stamped rather than looked up so a voided and re-posted transaction can find
     * its own journal without ambiguity.
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
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

    /**
     * Whether money came in. Alias of isCredit() that reads correctly at a call
     * site where direction is the point.
     */
    public function isInflow(): bool
    {
        return $this->isCredit();
    }

    public function signedAmount(): float
    {
        return $this->isDebit() ? -1 * (float) $this->amount : (float) $this->amount;
    }
}
