<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Account extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $auditModule = 'accounting';

    protected $fillable = [
        'code', 'name', 'type', 'sub_type', 'parent_id', 'currency', 'is_active', 'description',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Account::class, 'parent_id');
    }

    public function journalItems(): HasMany
    {
        return $this->hasMany(JournalEntryItem::class, 'account_id');
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
            $q->where('code', 'like', "%{$term}%")
                ->orWhere('name', 'like', "%{$term}%");
        });
    }

    public static function typeOptions(): array
    {
        return ['asset', 'liability', 'equity', 'revenue', 'expense'];
    }

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            'asset' => 'Asset',
            'liability' => 'Liability',
            'equity' => 'Equity',
            'revenue' => 'Revenue',
            'expense' => 'Expense',
            default => ucfirst($type),
        };
    }

    public function defaultSide(): string
    {
        return in_array($this->type, ['asset', 'expense'], true) ? 'debit' : 'credit';
    }

    /**
     * Normal balance for the account, computed from posted journal lines.
     * Debit-normal accounts show a positive debit balance and vice versa.
     */
    public function balance(): float
    {
        $debits = (float) $this->journalItems()->whereHas('entry', fn ($q) => $q->where('status', 'posted'))->sum('debit');
        $credits = (float) $this->journalItems()->whereHas('entry', fn ($q) => $q->where('status', 'posted'))->sum('credit');

        return round($debits - $credits, 2);
    }
}