<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetItem extends Model
{
    protected $fillable = [
        'budget_id', 'account_id', 'budget_amount',
    ];

    protected function casts(): array
    {
        return [
            'budget_amount' => 'decimal:2',
        ];
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    /**
     * Actual spend/activity for the account across the budget's fiscal year.
     */
    public function actualAmount(): float
    {
        $account = $this->account;

        if (! $account) {
            return 0.0;
        }

        $start = ($this->budget->fiscal_year).'-01-01';
        $end = ($this->budget->fiscal_year).'-12-31';

        $matching = $account->journalItems()
            ->whereHas('entry', fn ($q) => $q->where('status', 'posted')->whereBetween('entry_date', [$start, $end]))
            ->get();

        $amount = 0.0;

        foreach ($matching as $line) {
            if ($account->type === 'revenue') {
                $amount += (float) $line->credit - (float) $line->debit;
            } elseif ($account->type === 'expense') {
                $amount += (float) $line->debit - (float) $line->credit;
            }
        }

        return round($amount, 2);
    }
}