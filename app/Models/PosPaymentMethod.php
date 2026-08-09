<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PosPaymentMethod extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $auditModule = 'pos';

    protected $fillable = [
        'code', 'name', 'is_cash', 'is_active', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_cash' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q->where('name', 'like', "%{$term}%")->orWhere('code', 'like', "%{$term}%"));
    }
}