<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Spatie\Permission\Models\Permission as SpatiePermission;

class Permission extends SpatiePermission
{
    use Auditable;

    protected string $auditModule = 'settings';
    protected $auditExclude = ['updated', 'created'];
}
