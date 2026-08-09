<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class JournalEntry extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $auditModule = 'accounting';

    protected $fillable = [
        'number', 'entry_date', 'reference', 'description', 'status',
        'created_by', 'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'posted_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(JournalEntryItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('number', 'like', "%{$term}%")
                ->orWhere('reference', 'like', "%{$term}%")
                ->orWhere('description', 'like', "%{$term}%");
        });
    }

    public static function statusOptions(): array
    {
        return ['draft', 'posted', 'void'];
    }

    public function totalDebits(): float
    {
        return round((float) $this->items()->sum('debit'), 2);
    }

    public function totalCredits(): float
    {
        return round((float) $this->items()->sum('credit'), 2);
    }

    public function isBalanced(): bool
    {
        return $this->totalDebits() === $this->totalCredits() && $this->totalDebits() > 0;
    }
}