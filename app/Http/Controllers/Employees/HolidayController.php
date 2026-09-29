<?php

namespace App\Http\Controllers\Employees;

use App\Http\Controllers\Controller;
use App\Models\Holiday;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class HolidayController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorizePermission('employees.holidays.view');

        $year = (int) ($request->integer('year') ?: now()->year);
        $holidays = Holiday::query()->whereYear('holiday_date', $year)->orderBy('holiday_date')->get();

        return view('employees.holidays.index', compact('holidays', 'year'));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizePermission('employees.holidays.manage');

        $data = $request->validate([
            'holiday_date' => ['required', 'date', Rule::unique('holidays', 'holiday_date')],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ]);

        Holiday::create($data + ['created_by' => auth()->id(), 'is_default' => false, 'is_active' => $request->boolean('is_active', true)]);

        return back()->with('toasts', [['type' => 'success', 'message' => 'Holiday added.']]);
    }

    public function update(Request $request, Holiday $holiday): RedirectResponse
    {
        $this->authorizePermission('employees.holidays.manage');

        $data = $request->validate([
            'holiday_date' => ['required', 'date', Rule::unique('holidays', 'holiday_date')->ignore($holiday->id)],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ]);

        $holiday->update($data + ['is_active' => $request->boolean('is_active')]);

        return back()->with('toasts', [['type' => 'success', 'message' => 'Holiday updated.']]);
    }

    public function destroy(Holiday $holiday): RedirectResponse
    {
        $this->authorizePermission('employees.holidays.manage');

        $holiday->delete();

        return back()->with('toasts', [['type' => 'success', 'message' => 'Holiday removed.']]);
    }
}
