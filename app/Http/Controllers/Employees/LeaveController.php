<?php

namespace App\Http\Controllers\Employees;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Support\ExportsCsv;
use App\Support\ExportsJson;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LeaveController extends Controller
{
    use ExportsCsv;
    use ExportsJson;

    /**
     * Management list — visible to HR / Admin.
     */
    public function index(Request $request): View
    {
        $requests = LeaveRequest::query()
            ->with(['employee', 'reviewer'])
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('employee_id'), fn ($q) => $q->where('employee_id', $request->employee_id))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('start_date', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('end_date', '<=', $request->to))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $employees = Employee::query()->where('employment_status', 'active')->orderBy('first_name')->get(['id', 'first_name', 'last_name']);

        $pending = LeaveRequest::query()->where('status', 'pending')->count();
        $approved = LeaveRequest::query()->where('status', 'approved')->count();
        $rejected = LeaveRequest::query()->where('status', 'rejected')->count();

        $thisMonthDays = (float) LeaveRequest::query()
            ->where('status', 'approved')
            ->where('start_date', '<=', now()->endOfMonth()->toDateString())
            ->where('end_date', '>=', now()->startOfMonth()->toDateString())
            ->sum('days');

        return view('employees.leave.index', compact('requests', 'employees', 'pending', 'approved', 'rejected', 'thisMonthDays'));
    }

    /**
     * The logged-in employee's own requests.
     */
    public function my(): View
    {
        $employee = $this->currentEmployee();

        if (! $employee) {
            return view('employees.leave.my', ['employee' => null, 'requests' => collect()]);
        }

        $requests = $employee->leaveRequests()
            ->with('reviewer')
            ->orderByDesc('created_at')
            ->get();

        return view('employees.leave.my', compact('employee', 'requests'));
    }

    public function myCreate(): View
    {
        $employee = $this->currentEmployee();
        abort_unless($employee, 403, 'Your account is not linked to an employee profile.');

        return view('employees.leave.create', compact('employee'));
    }

    public function myStore(Request $request): RedirectResponse
    {
        $employee = $this->currentEmployee();
        abort_unless($employee, 403, 'Your account is not linked to an employee profile.');

        $data = $this->validateLeave($request);

        $this->ensureNoOverlap($employee, $data['start_date'], $data['end_date']);

        $days = LeaveRequest::coveredDays($data['start_date'], $data['end_date']);

        $leave = LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type' => $data['leave_type'],
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'days' => $days,
            'reason' => $data['reason'],
            'status' => 'pending',
        ]);

        return redirect()->route('employees.leave.my')
            ->with('toasts', [['type' => 'success', 'message' => "Leave request submitted for {$days} day(s)."]]);
    }

    /**
     * Employee cancels their own pending request.
     */
    public function myCancel(Request $request, LeaveRequest $leave): RedirectResponse
    {
        $employee = $this->currentEmployee();
        abort_unless($employee && (int) $leave->employee_id === (int) $employee->id, 403);

        if ($leave->status !== 'pending') {
            return back()->with('toasts', [['type' => 'danger', 'message' => 'Only pending requests can be cancelled.']]);
        }

        $leave->update(['status' => 'cancelled']);

        return back()->with('toasts', [['type' => 'success', 'message' => 'Leave request cancelled.']]);
    }

    public function show(LeaveRequest $leave): View
    {
        $this->authorizeView($leave);

        $leave->load(['employee', 'reviewer']);

        return view('employees.leave.show', compact('leave'));
    }

    public function approve(Request $request, LeaveRequest $leave): RedirectResponse
    {
        abort_unless($this->isManager(), 403);

        if ($leave->status !== 'pending') {
            return back()->with('toasts', [['type' => 'danger', 'message' => 'Only pending requests can be approved.']]);
        }

        $leave->update([
            'status' => 'approved',
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        return back()->with('toasts', [['type' => 'success', 'message' => "Leave request for {$leave->employee?->fullName()} approved."]]);
    }

    public function reject(Request $request, LeaveRequest $leave): RedirectResponse
    {
        abort_unless($this->isManager(), 403);

        if ($leave->status !== 'pending') {
            return back()->with('toasts', [['type' => 'danger', 'message' => 'Only pending requests can be rejected.']]);
        }

        $data = $request->validate(['review_notes' => ['required', 'string', 'max:2000']]);

        $leave->update([
            'status' => 'rejected',
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
            'review_notes' => $data['review_notes'],
        ]);

        return back()->with('toasts', [['type' => 'success', 'message' => 'Leave request rejected.']]);
    }

    public function export(Request $request): StreamedResponse
    {
        $requests = LeaveRequest::query()
            ->with(['employee', 'reviewer'])
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('employee_id'), fn ($q) => $q->where('employee_id', $request->employee_id))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('start_date', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('end_date', '<=', $request->to))
            ->orderByDesc('created_at')
            ->get();

        $rows = $requests->map(fn (LeaveRequest $l) => [
            'employee' => $l->employee?->fullName(),
            'employee_code' => $l->employee?->employee_code,
            'type' => ucfirst($l->leave_type),
            'start' => $l->start_date?->format('Y-m-d'),
            'end' => $l->end_date?->format('Y-m-d'),
            'days' => $l->days,
            'reason' => $l->reason,
            'status' => ucfirst($l->status),
            'reviewed_by' => $l->reviewer?->name,
            'reviewed_at' => $l->reviewed_at?->format('Y-m-d H:i'),
            'review_notes' => $l->review_notes,
        ])->values();

        $filename = 'leave-requests-'.now()->format('Y-m-d').'.'.($request->query('format') === 'json' ? 'json' : 'csv');

        return $request->query('format') === 'json'
            ? $this->streamJson($filename, $rows)
            : $this->streamCsv($filename, ['Employee', 'Code', 'Type', 'Start', 'End', 'Days', 'Reason', 'Status', 'Reviewed by', 'Reviewed at', 'Review notes'], $rows);
    }

    /**
     * Manager = HR or Admin.
     */
    private function isManager(): bool
    {
        $user = auth()->user();

        return $user && ($user->isAdmin() || $user->hasRole('HR'));
    }

    private function currentEmployee(): ?Employee
    {
        return Employee::query()->where('user_id', auth()->id())->first();
    }

    private function authorizeView(LeaveRequest $leave): void
    {
        if ($this->isManager()) {
            return;
        }

        $employee = $this->currentEmployee();

        abort_if(! $employee || (int) $leave->employee_id !== (int) $employee->id, 403, 'You can only view your own leave requests.');
    }

    private function validateLeave(Request $request): array
    {
        $data = Validator::make($request->all(), [
            'leave_type' => ['required', 'string', 'max:40', 'in:'.implode(',', LeaveRequest::typeOptions())],
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason' => ['required', 'string', 'max:5000'],
        ])->validate();

        // Normalize to a max of 366 days to avoid absurd inputs.
        if (LeaveRequest::coveredDays($data['start_date'], $data['end_date']) > 366) {
            throw ValidationException::withMessages(['end_date' => 'Leave cannot exceed 366 days.']);
        }

        return $data;
    }

    /**
     * Block requests that overlap another pending or approved request so the
     * same days are not double-booked. Rejected/cancelled dates are free.
     */
    private function ensureNoOverlap(Employee $employee, string $start, string $end): void
    {
        $overlap = $employee->leaveRequests()
            ->whereIn('status', ['pending', 'approved'])
            ->whereDate('start_date', '<=', $end)
            ->whereDate('end_date', '>=', $start)
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages([
                'start_date' => 'These dates overlap an existing pending or approved leave request.',
            ]);
        }
    }
}