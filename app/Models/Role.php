<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    use Auditable;

    protected string $auditModule = 'settings';

    protected $auditExclude = ['updated'];

    public function dashboardWidgets(): HasMany
    {
        return $this->hasMany(DashboardWidgetPreference::class)->orderBy('sort_order');
    }

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }
}
