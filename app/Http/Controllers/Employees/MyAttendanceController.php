<?php

namespace App\Http\Controllers\Employees;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\AttendanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class MyAttendanceController extends Controller
{
    public function __construct(private readonly AttendanceService $attendance)
    {
    }

    public function index(): View
    {
        $employee = Employee::query()->where('user_id', auth()->id())->first();

        if (! $employee) {
            return view('employees.my_attendance.index', ['employee' => null]);
        }

        $records = $employee->attendanceRecords()
            ->where('attendance_date', '>=', now()->startOfMonth()->toDateString())
            ->orderByDesc('attendance_date')
            ->get();

        $today = $employee->attendanceRecords()
            ->where('attendance_date', now()->toDateString())
            ->first();

        return view('employees.my_attendance.index', compact('employee', 'records', 'today'));
    }

    public function mark(): RedirectResponse
    {
        $employee = Employee::query()->where('user_id', auth()->id())->first();

        if (! $employee) {
            return back()->with('toasts', [['type' => 'danger', 'message' => 'No employee profile is linked to your account.']]);
        }

        $result = $this->attendance->recordScan($employee, 'manual');

        if ($result['status'] === 'error') {
            return back()->with('toasts', [['type' => 'danger', 'message' => $result['message']]]);
        }

        $action = $result['type'] === 'in' ? 'Clock-in recorded' : 'Clock-out recorded';

        return back()->with('toasts', [['type' => 'success', 'message' => "{$action} at ".now()->format('H:i').'.']]);
    }
}
