<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Visit extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $auditModule = 'visits';

    protected $fillable = [
        'visit_number', 'customer_id', 'sales_rep_id', 'purpose', 'notes',
        'status', 'scheduled_at', 'started_at', 'completed_at', 'distance_km',
        'start_lat', 'start_lng', 'outcome', 'outcome_notes',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'date',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'distance_km' => 'decimal:2',
        ];
    }

    public static function statusOptions(): array
    {
        return ['pending', 'started', 'completed', 'cancelled'];
    }

    public static function outcomeOptions(): array
    {
        return [
            'attended', 'closed_deal', 'rescheduled', 'no_contact', 'not_interested',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(SalesCustomer::class, 'customer_id');
    }

    public function salesRep(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'sales_rep_id');
    }

    public function pitStops(): HasMany
    {
        return $this->hasMany(VisitPitstop::class, 'visit_id')->orderBy('visited_at');
    }

    public function totalDistanceKm(): float
    {
        return round((float) $this->pitStops()->sum('distance_km'), 2);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', ['pending', 'started']);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('visit_number', 'like', "%{$term}%")
                ->orWhere('purpose', 'like', "%{$term}%")
                ->orWhereHas('customer', fn (Builder $c) => $c->where('company_name', 'like', "%{$term}%"));
        });
    }
}