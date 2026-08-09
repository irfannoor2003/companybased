<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\AttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceApiController extends Controller
{
    public function __construct(private readonly AttendanceService $attendance)
    {
    }

    public function scan(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_code' => ['required', 'string'],
            'method' => ['required', 'in:qr,fingerprint'],
            'timestamp' => ['nullable', 'date'],
        ]);

        $employee = Employee::where('employee_code', $data['employee_code'])->first();

        if (!$employee) {
            return response()->json(['error' => 'Employee not found.'], 404);
        }

        $time = $request->filled('timestamp') ? \Carbon\Carbon::parse($data['timestamp']) : now();

        $result = $this->attendance->recordScan($employee, $data['method'], $time);

        if ($result['status'] === 'error') {
            return response()->json(['error' => $result['message']], 400);
        }

        return response()->json([
            'status' => 'success',
            'type' => $result['type'],
            'message' => $result['type'] === 'in' ? 'Clock-in recorded.' : 'Clock-out recorded.',
            'employee' => $employee->fullName(),
            'time' => $time->toDateTimeString()
        ]);
    }
}
