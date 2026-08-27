<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DiscountRule extends Model
{
    protected $fillable = [
        'name', 'description', 'type', 'max_value', 'currency',
        'applicable_to', 'roles', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'applicable_to' => 'array',
            'roles' => 'array',
            'max_value' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForRole($query, string $role)
    {
        return $query->whereJsonContains('roles', $role);
    }

    public function appliesToRole(string $role): bool
    {
        return in_array($role, $this->roles ?? []);
    }

    public function getMaxDiscountLabelAttribute(): string
    {
        if ($this->type === 'percentage') {
            return number_format($this->max_value, 0) . '%';
        }

        return ($this->currency ?? 'USD') . ' ' . number_format($this->max_value, 2);
    }
}
