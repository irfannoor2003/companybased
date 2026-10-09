<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalEntryItem extends Model
{
    protected $fillable = [
        'journal_entry_id', 'account_id', 'debit', 'credit', 'memo',
        'currency', 'exchange_rate', 'original_debit', 'original_credit',
    ];

    protected function casts(): array
    {
return [
  'debit' => 'decimal:2',
  'credit' => 'decimal:2',

  /**
   * Held at 4 decimal places rather than 2. Exchange rates carry more
   * significant figures than a money amount, and a rate rounded to cents
   * silently misstates the converted figure on every line.
   */
  'exchange_rate' => 'decimal:8',
  'original_debit' => 'decimal:4',
  'original_credit' => 'decimal:4',
  ];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }
}