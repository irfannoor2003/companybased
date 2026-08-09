<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Module;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ModuleController extends Controller
{
    public function index(): View
    {
        $modules = Module::query()->orderBy('sort_order')->get();

        return view('settings.modules', compact('modules'));
    }

    public function update(Request $request, Module $module): RedirectResponse
    {
        $this->authorizePermission('settings.modules.manage');

        if ($module->is_core) {
            abort(403, 'Core modules cannot be disabled.');
        }

        $module->update([
            'enabled' => $request->boolean('enabled'),
        ]);

        Module::flushCache();

        $state = $module->enabled ? 'enabled' : 'disabled';

        return back()->with('toasts', [['type' => 'success', 'message' => "Module \"{$module->label}\" {$state}."]]);
    }
}
