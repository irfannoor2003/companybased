<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Brand extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'description', 'is_active',
    ];

    protected $auditModule = 'catalog';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function productCount(): int
    {
        return $this->products()->count();
    }
}
