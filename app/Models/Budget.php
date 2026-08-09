<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Budget extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $auditModule = 'accounting';

    protected $fillable = [
        'name', 'fiscal_year', 'currency', 'description', 'status',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(BudgetItem::class);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('fiscal_year', 'like', "%{$term}%");
        });
    }

    public static function statusOptions(): array
    {
        return ['draft', 'active', 'closed'];
    }

    public function totalBudgeted(): float
    {
        return round((float) $this->items()->sum('budget_amount'), 2);
    }
}