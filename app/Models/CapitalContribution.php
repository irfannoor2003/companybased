<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CapitalContribution extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $auditModule = 'capital_accounts';

    protected $fillable = [
        'reference', 'contribution_date', 'contributor', 'amount',
        'currency', 'method', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'contribution_date' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    public static function methodOptions(): array
    {
        return ['cash', 'bank_transfer', 'cheque', 'other'];
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('reference', 'like', "%{$term}%")
                ->orWhere('contributor', 'like', "%{$term}%")
                ->orWhere('notes', 'like', "%{$term}%");
        });
    }
}