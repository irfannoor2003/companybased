<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Category extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'parent_id', 'description', 'is_active',
    ];

    protected $auditModule = 'catalog';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Ancestor path, e.g. "Hardware > Pipes". Always ends with this category.
     */
    public function path(): string
    {
        $parts = [$this->name];
        $node = $this->parent;

        while ($node) {
            array_unshift($parts, $node->name);
            $node = $node->parent;
        }

        return implode(' > ', $parts);
    }
}
