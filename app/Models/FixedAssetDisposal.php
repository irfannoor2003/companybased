<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class FixedAssetDisposal extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $auditModule = 'fixed_assets';

    protected $fillable = [
        'fixed_asset_id', 'disposal_date', 'method', 'proceeds',
        'book_value', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'disposal_date' => 'date',
            'proceeds' => 'decimal:2',
            'book_value' => 'decimal:2',
        ];
    }

    public static function methodOptions(): array
    {
        return ['sold', 'scrapped', 'donated', 'other'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }
}