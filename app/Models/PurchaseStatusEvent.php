<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PurchaseStatusEvent extends Model
{
    protected $table = 'purchase_status_events';

    protected $fillable = [
        'trackable_type', 'trackable_id', 'from_status', 'to_status', 'user_id', 'note',
    ];

    public function trackable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
