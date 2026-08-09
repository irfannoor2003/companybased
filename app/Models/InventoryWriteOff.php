<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class InventoryWriteOff extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $fillable = [
        'number', 'warehouse_id', 'write_off_date', 'reason', 'status', 'note',
    ];

    protected $auditModule = 'inventory';

    protected function casts(): array
    {
        return [
            'write_off_date' => 'date',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(InventoryWarehouse::class, 'warehouse_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InventoryWriteOffItem::class, 'write_off_id');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        return $query->where('number', 'like', "%{$term}%");
    }

    public static function statusOptions(): array
    {
        return ['draft', 'completed', 'cancelled'];
    }
}
