<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class InventoryTransfer extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $fillable = [
        'number', 'from_warehouse_id', 'to_warehouse_id', 'transfer_date', 'status', 'note',
    ];

    protected $auditModule = 'inventory';

    protected function casts(): array
    {
        return [
            'transfer_date' => 'date',
        ];
    }

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(InventoryWarehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(InventoryWarehouse::class, 'to_warehouse_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InventoryTransferItem::class, 'transfer_id');
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
        return ['draft', 'pending', 'completed', 'cancelled'];
    }
}
