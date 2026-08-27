<?php

namespace App\Http\Controllers\Employees;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\AttendanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function mark(Request $request): RedirectResponse
    {
        $employee = Employee::query()->where('user_id', auth()->id())->first();

        if (! $employee) {
            return back()->with('toasts', [['type' => 'error', 'message' => 'No employee profile is linked to your account.']]);
        }

        // Validate location and QR code text are provided
        $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'qr_text' => ['required', 'string'],
        ]);

        // Validate QR code text matches the office QR code
        $officeQrText = settings('company.qr_code_text', '');
        if ($officeQrText && $request->qr_text !== $officeQrText) {
            return back()->with('toasts', [['type' => 'error', 'message' => 'QR code does not match the office attendance code.']]);
        }

        // Check if employee is within office radius
        $officeLat = (float) settings('company.latitude', 0);
        $officeLng = (float) settings('company.longitude', 0);
        $radius = (float) settings('company.radius', 500);

        $distance = $this->haversineDistance(
            $request->latitude, $request->longitude,
            $officeLat, $officeLng
        );

        if ($distance > $radius) {
            return back()->with('toasts', [['type' => 'error', 'message' => 'You must be within the office radius ('.number_format($radius, 0).'m) to mark attendance. Current distance: '.number_format($distance, 0).'m']]);
        }

        $result = $this->attendance->recordScan($employee, 'manual');

        if ($result['status'] === 'error') {
            return back()->with('toasts', [['type' => 'error', 'message' => $result['message']]]);
        }

        $action = $result['type'] === 'in' ? 'Clock-in recorded' : 'Clock-out recorded';

        return back()->with('toasts', [['type' => 'success', 'message' => "{$action} at ".now()->format('H:i').'.']]);
    }

    /**
     * Calculate distance between two points using the Haversine formula.
     * Returns distance in meters.
     */
    private function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000; // meters

        $latDelta = deg2rad($lat2 - $lat1);
        $lngDelta = deg2rad($lng2 - $lng1);

        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($lngDelta / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}
