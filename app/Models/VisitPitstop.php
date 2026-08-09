<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class VisitPitstop extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $auditModule = 'visits';

    protected $fillable = [
        'visit_id', 'customer_id', 'purpose', 'notes', 'distance_km',
        'image_path', 'visited_at', 'lat', 'lng',
    ];

    protected function casts(): array
    {
        return [
            'visited_at' => 'datetime',
            'distance_km' => 'decimal:2',
        ];
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class, 'visit_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(SalesCustomer::class, 'customer_id');
    }
}