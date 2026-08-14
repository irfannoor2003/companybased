<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Module;
use App\Models\Role;
use App\Models\User;

class DashboardController extends Controller
{
    public function __invoke()
    {
        $enabledModules = Module::query()->where('enabled', true)->orderBy('sort_order')->get();
        $disabledModules = Module::query()->where('enabled', false)->count();

        $recentActivity = AuditLog::query()
            ->with('user')
            ->latest('id')
            ->limit(10)
            ->get();

        $stats = [
            'users' => User::count(),
            'roles' => Role::where('name', '!=', 'Super Admin')->count(),
            'modulesEnabled' => $enabledModules->count(),
            'modulesTotal' => Module::count(),
        ];

        $isAdmin = auth()->user()->isAdmin();
        $isSuperAdmin = auth()->user()->isSuperAdmin();

        return view('dashboard', compact('enabledModules', 'disabledModules', 'recentActivity', 'stats', 'isAdmin', 'isSuperAdmin'));
    }
}
