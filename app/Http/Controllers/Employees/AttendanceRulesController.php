<?php

namespace App\Http\Controllers\Employees;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\AttendanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AttendanceRulesController extends Controller
{
    public function edit(): View
    {
        $rules = (new AttendanceService)->rules();
        $defaults = AttendanceService::defaults();

        return view('employees.attendance.rules', compact('rules', 'defaults'));
    }

    public function update(Request $request): RedirectResponse
    {
        $types = ['flat', 'percent'];

        $data = $request->validate([
            'shift_start' => ['required', 'date_format:H:i'],
            'shift_end' => ['required', 'date_format:H:i', 'after:shift_start'],
            'grace_minutes' => ['required', 'integer', 'min:0', 'max:240'],
            'short_leave_threshold_minutes' => ['required', 'integer', 'min:0', 'max:480'],
            'half_day_cutoff_minutes' => ['required', 'integer', 'min:0', 'max:600'],
            'weekend_days' => ['nullable', 'array'],
            'weekend_days.*' => ['string', Rule::in(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'])],
            'deduction_late_type' => ['required', Rule::in($types)],
            'deduction_late_amount' => ['required', 'numeric', 'min:0'],
            'deduction_short_leave_type' => ['required', Rule::in($types)],
            'deduction_short_leave_amount' => ['required', 'numeric', 'min:0'],
            'deduction_absent_type' => ['required', Rule::in($types)],
            'deduction_absent_amount' => ['required', 'numeric', 'min:0'],
        ]);

        Setting::setMany([
            'attendance.shift_start' => $data['shift_start'],
            'attendance.shift_end' => $data['shift_end'],
            'attendance.grace_minutes' => (string) $data['grace_minutes'],
            'attendance.short_leave_threshold_minutes' => (string) $data['short_leave_threshold_minutes'],
            'attendance.half_day_cutoff_minutes' => (string) $data['half_day_cutoff_minutes'],
            'attendance.weekend_days' => $data['weekend_days'] ?? [],
            'attendance.deduction_late_type' => $data['deduction_late_type'],
            'attendance.deduction_late_amount' => (string) $data['deduction_late_amount'],
            'attendance.deduction_short_leave_type' => $data['deduction_short_leave_type'],
            'attendance.deduction_short_leave_amount' => (string) $data['deduction_short_leave_amount'],
            'attendance.deduction_absent_type' => $data['deduction_absent_type'],
            'attendance.deduction_absent_amount' => (string) $data['deduction_absent_amount'],
        ], 'attendance');

        return back()->with('toasts', [['type' => 'success', 'message' => 'Attendance rules updated.']]);
    }
}