<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CapitalDrawing extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $auditModule = 'capital_accounts';

    protected $fillable = [
        'reference', 'drawing_date', 'recipient', 'amount',
        'currency', 'method', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'drawing_date' => 'date',
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
                ->orWhere('recipient', 'like', "%{$term}%")
                ->orWhere('notes', 'like', "%{$term}%");
        });
    }
}