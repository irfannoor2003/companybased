<?php

namespace App\Http\Controllers\Employees;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Support\ExportsCsv;
use App\Support\ExportsJson;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeController extends Controller
{
    use ExportsCsv;
    use ExportsJson;

    public function index(Request $request): View
    {
        $employees = Employee::query()
            ->with(['department', 'user'])
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->when($request->filled('department_id'), fn ($q) => $q->where('department_id', $request->department_id))
            ->when($request->filled('status'), fn ($q) => $q->where('employment_status', $request->status))
            ->orderBy('first_name')
            ->paginate(20)
            ->withQueryString();

        $departments = Department::query()->orderBy('name')->get(['id', 'name']);

        $totalActive = Employee::query()->where('employment_status', 'active')->count();
        $onLeave = Employee::query()->where('employment_status', 'on_leave')->count();
        $attendanceEnabled = Employee::query()->where('attendance_enabled', true)->count();

        return view('employees.employees.index', compact('employees', 'departments', 'totalActive', 'onLeave', 'attendanceEnabled'));
    }

    public function create(): View
    {
        return view('employees.employees.create', [
            'departments' => Department::query()->active()->orderBy('name')->get(['id', 'name']),
            'users' => User::query()->orderBy('name')->get(['id', 'name', 'email']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

        $employee = Employee::create([
            'user_id' => $data['user_id'] ?? null,
            'employee_code' => $data['employee_code'],
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'date_of_birth' => $data['date_of_birth'] ?? null,
            'date_hired' => $data['date_hired'],
            'department_id' => $data['department_id'] ?? null,
            'job_title' => $data['job_title'] ?? null,
            'employment_status' => $data['employment_status'],
            'address' => $data['address'] ?? null,
            'attendance_enabled' => $request->boolean('attendance_enabled', true),
        ]);

        return redirect()->route('employees.employees.show', $employee)
            ->with('toasts', [['type' => 'success', 'message' => "Employee {$employee->fullName()} created."]]);
    }

    public function show(Employee $employee): View
    {
        $employee->load(['department', 'user', 'salaryStructures', 'documents.uploader']);

        $attendance = $employee->attendanceRecords()
            ->orderByDesc('attendance_date')
            ->limit(10)
            ->get();

        return view('employees.employees.show', compact('employee', 'attendance'));
    }

    public function edit(Employee $employee): View
    {
        return view('employees.employees.edit', [
            'employee' => $employee,
            'departments' => Department::query()->active()->orderBy('name')->get(['id', 'name']),
            'users' => User::query()->orderBy('name')->get(['id', 'name', 'email']),
        ]);
    }

    public function update(Request $request, Employee $employee): RedirectResponse
    {
        $data = $this->validateData($request, $employee);

        $employee->update([
            'user_id' => $data['user_id'] ?? null,
            'employee_code' => $data['employee_code'],
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'date_of_birth' => $data['date_of_birth'] ?? null,
            'date_hired' => $data['date_hired'],
            'department_id' => $data['department_id'] ?? null,
            'job_title' => $data['job_title'] ?? null,
            'employment_status' => $data['employment_status'],
            'address' => $data['address'] ?? null,
            'attendance_enabled' => $request->boolean('attendance_enabled', $employee->attendance_enabled),
        ]);

        return back()->with('toasts', [['type' => 'success', 'message' => "Employee {$employee->fullName()} updated."]]);
    }

    public function destroy(Employee $employee): RedirectResponse
    {
        $employee->delete();

        return redirect()->route('employees.employees.index')
            ->with('toasts', [['type' => 'success', 'message' => "Employee {$employee->fullName()} deleted."]]);
    }

    public function export(Request $request): StreamedResponse
    {
        $employees = Employee::query()
            ->with(['department', 'user'])
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->when($request->filled('department_id'), fn ($q) => $q->where('department_id', $request->department_id))
            ->when($request->filled('status'), fn ($q) => $q->where('employment_status', $request->status))
            ->orderBy('first_name')
            ->get();

        $rows = $employees->map(fn (Employee $e) => [
            'code' => $e->employee_code,
            'name' => $e->fullName(),
            'email' => $e->email,
            'phone' => $e->phone,
            'department' => $e->department?->name,
            'job_title' => $e->job_title,
            'date_hired' => $e->date_hired->format('Y-m-d'),
            'status' => ucfirst(str_replace('_', ' ', $e->employment_status)),
            'attendance_enabled' => $e->attendance_enabled ? 'Yes' : 'No',
        ]);

        return $request->query('format') === 'json'
            ? $this->streamJson('employees-'.now()->format('Y-m-d').'.json', $rows)
            : $this->streamCsv('employees-'.now()->format('Y-m-d').'.csv', ['Code', 'Name', 'Email', 'Phone', 'Department', 'Job title', 'Hired', 'Status', 'Attendance enabled'], $rows->values());
    }

    private function validateData(Request $request, ?Employee $ignore = null): array
    {
        return $request->validate([
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'employee_code' => ['required', 'string', 'max:30', Rule::unique('employees', 'employee_code')->ignore($ignore?->id)],
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'date_hired' => ['required', 'date'],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
            'job_title' => ['nullable', 'string', 'max:120'],
            'employment_status' => ['required', Rule::in(Employee::employmentStatusOptions())],
            'address' => ['nullable', 'string', 'max:5000'],
            'attendance_enabled' => ['nullable', 'boolean'],
        ]);
    }
}
