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

    /**
     * Whether this rule applies to a user holding ANY of $roles.
     *
     * The old caller spread every role name into appliesToRole(), which takes
     * one string: PHP ignored the extras, so a user who was both Salesman and
     * HR was matched on whichever role happened to come first. Checking the
     * whole set is what "applies to my roles" actually means.
     *
     * @param  array<int,string>  $roles
     */
    public function appliesToRoles(array $roles): bool
    {
        return count(array_intersect($roles, $this->roles ?? [])) > 0;
    }

    public function getMaxDiscountLabelAttribute(): string
    {
        if ($this->type === 'percentage') {
            return number_format($this->max_value, 0) . '%';
        }

        return ($this->currency ?? 'USD') . ' ' . number_format($this->max_value, 2);
    }
}
