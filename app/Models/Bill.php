<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Bill extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $auditModule = 'accounting';

    protected $fillable = [
        'number', 'vendor_name', 'supplier_id', 'bill_date', 'due_date',
        'amount', 'paid_amount', 'currency', 'status', 'reference', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'bill_date' => 'date',
            'due_date' => 'date',
            'amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(BillItem::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('number', 'like', "%{$term}%")
                ->orWhere('vendor_name', 'like', "%{$term}%")
                ->orWhere('reference', 'like', "%{$term}%");
        });
    }

    public static function statusOptions(): array
    {
        return ['draft', 'open', 'partially_paid', 'paid', 'void'];
    }

    public function balance(): float
    {
        return round((float) $this->amount - (float) $this->paid_amount, 2);
    }

    public function isPaid(): bool
    {
        return $this->balance() <= 0;
    }
}