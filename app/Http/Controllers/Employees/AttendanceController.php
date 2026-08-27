<?php

namespace App\Http\Controllers\Employees;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Services\AttendanceService;
use App\Support\ExportsCsv;
use App\Support\ExportsJson;
use Endroid\QrCode\Encoding\Utf8Encoding;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelHigh;
use Endroid\QrCode\Label\LabelAlignment\LabelAlignmentCenter;
use Endroid\QrCode\Label\LabelFactory;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\QrCodeInterface;
use Endroid\QrCode\Writer\WriterInterface;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttendanceController extends Controller
{
    use ExportsCsv;
    use ExportsJson;

    public function __construct(private readonly AttendanceService $attendance)
    {
    }

    public function index(Request $request): View
    {
        $date = $request->filled('date') ? $request->date : now()->toDateString();

        $records = AttendanceRecord::query()
            ->with('employee.department')
            ->when($request->filled('date'), fn ($q) => $q->where('attendance_date', $request->date), fn ($q) => $q->where('attendance_date', $date))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('employee_id'), fn ($q) => $q->where('employee_id', $request->employee_id))
            ->orderBy('check_in_at')
            ->paginate(30)
            ->withQueryString();

        $employees = Employee::query()->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'employee_code']);

        $present = AttendanceRecord::query()->where('attendance_date', $date)->whereIn('status', ['present', 'late'])->count();
        $late = AttendanceRecord::query()->where('attendance_date', $date)->where('status', 'late')->count();
        $absent = AttendanceRecord::query()->where('attendance_date', $date)->where('status', 'absent')->count();
        $totalEnabled = Employee::query()->where('attendance_enabled', true)->count();

        return view('employees.attendance.index', compact('records', 'employees', 'date', 'present', 'late', 'absent', 'totalEnabled'));
    }

    /**
     * Admin marks check-in/check-out for an employee (manual fallback).
     */
    public function mark(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $employee = Employee::findOrFail($data['employee_id']);

        $result = $this->attendance->recordScan($employee, 'manual', now(), $data['notes'] ?? null);

        if ($result['status'] === 'error') {
            return back()->with('toasts', [['type' => 'danger', 'message' => $result['message']]]);
        }

        $action = $result['type'] === 'in' ? 'checked in' : 'checked out';

        return back()->with('toasts', [['type' => 'success', 'message' => "{$employee->fullName()} {$action}."]]);
    }

    /**
     * Mobile QR scan target: a time-limited signed URL performs check-in/out
     * without a login (the physical QR code is the credential).
     */
    public function qr(string $code, Request $request): \Illuminate\Contracts\View\View|RedirectResponse
    {
        $signature = $request->query('s');
        $timestamp = (int) $request->query('t');

        $validSignature = hash_equals(
            $this->qrSignature($code, $timestamp),
            (string) $signature,
        );

        if (! $validSignature || abs(now()->timestamp - $timestamp) > 180) {
            return redirect()->route('login');
        }

        $employee = Employee::query()->where('employee_code', $code)->first();

        if (! $employee) {
            return view('employees.attendance.qr-result', ['ok' => false, 'message' => 'Unknown employee code.']);
        }

        $result = $this->attendance->recordScan($employee, 'qr');

        return view('employees.attendance.qr-result', [
            'ok' => $result['status'] === 'success',
            'message' => $result['status'] === 'success'
                ? ($result['type'] === 'in' ? 'Clock-in recorded.' : 'Clock-out recorded.')
                : $result['message'],
            'name' => $employee->fullName(),
            'time' => now()->format('H:i:s'),
        ]);
    }

    public function qrSignature(string $code, int $timestamp): string
    {
        return hash_hmac('sha256', $code.'|'.$timestamp, (string) config('app.key'));
    }

    /**
     * Display the office QR code for attendance.
     */
    public function qrCode(): View
    {
        $qrText = settings('company.qr_code_text', 'COMPANYBASE-OFFICE-ATTENDANCE-2026');

        $qrCode = QrCode::create($qrText)
            ->setEncoding(new \Endroid\QrCode\Encoding\EncodingInterface('UTF-8'))
            ->setErrorCorrectionLevel(new ErrorCorrectionLevelHigh())
            ->setSize(300)
            ->setMargin(10);

        $writer = new PngWriter();
        $result = $writer->write($qrCode);
        $base64 = 'data:image/png;base64,' . base64_encode($result->getString());

        return view('employees.attendance.qr-code', compact('qrText', 'base64'));
    }

    /**
     * Download the office QR code as a PNG file.
     */
    public function downloadQrCode()
    {
        $qrText = settings('company.qr_code_text', 'COMPANYBASE-OFFICE-ATTENDANCE-2026');

        $qrCode = QrCode::create($qrText)
            ->setEncoding(new \Endroid\QrCode\Encoding\EncodingInterface('UTF-8'))
            ->setErrorCorrectionLevel(new ErrorCorrectionLevelHigh())
            ->setSize(400)
            ->setMargin(10);

        $writer = new PngWriter();
        $result = $writer->write($qrCode);

        $fileName = 'office-attendance-qr-code.png';

        return Response::make($result->getString(), 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
        ]);
    }

    public function update(Request $request, AttendanceRecord $record): RedirectResponse
    {
        $data = $request->validate([
            'check_in_at' => ['nullable', 'date'],
            'check_out_at' => ['nullable', 'date', 'after:check_in_at'],
            'status' => ['required', Rule::in(AttendanceRecord::statusOptions())],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $record->check_in_at = $data['check_in_at'] ?: null;
        $record->check_out_at = $data['check_out_at'] ?: null;
        $record->status = $data['status'];
        $record->notes = $data['notes'] ?? null;

        $this->attendance->applyRules($record);
        $record->save();

        return back()->with('toasts', [['type' => 'success', 'message' => 'Attendance updated.']]);
    }

    public function destroy(AttendanceRecord $record): RedirectResponse
    {
        $record->delete();

        return back()->with('toasts', [['type' => 'success', 'message' => 'Attendance record removed.']]);
    }

    public function export(Request $request): StreamedResponse
    {
        $records = AttendanceRecord::query()
            ->with('employee.department')
            ->when($request->filled('date'), fn ($q) => $q->where('attendance_date', $request->date), fn ($q) => $q->where('attendance_date', now()->toDateString()))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('employee_id'), fn ($q) => $q->where('employee_id', $request->employee_id))
            ->orderBy('check_in_at')
            ->get();

        $rows = $records->map(fn (AttendanceRecord $r) => [
            'date' => $r->attendance_date->format('Y-m-d'),
            'employee_code' => $r->employee?->employee_code,
            'employee' => $r->employee?->fullName(),
            'department' => $r->employee?->department?->name,
            'check_in' => $r->check_in_at?->format('H:i'),
            'check_out' => $r->check_out_at?->format('H:i'),
            'work_minutes' => $r->work_minutes,
            'method' => $r->method,
            'status' => $r->status ? ucfirst(str_replace('_', ' ', $r->status)) : null,
        ]);

        return $request->query('format') === 'json'
            ? $this->streamJson('attendance-'.$request->date.'-'.now()->format('Y-m-d').'.json', $rows)
            : $this->streamCsv('attendance-'.$request->date.'-'.now()->format('Y-m-d').'.csv', ['Date', 'Code', 'Employee', 'Department', 'Check in', 'Check out', 'Work minutes', 'Method', 'Status'], $rows->values());
    }
}
