<?php

namespace App\Http\Controllers\Employees;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Employee;
use App\Support\ExportsCsv;
use App\Support\ExportsJson;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DepartmentController extends Controller
{
    use ExportsCsv;
    use ExportsJson;

    public function index(Request $request): View
    {
        $departments = Department::query()
            ->withCount('employees')
            ->with('headOfDepartment')
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->when($request->filled('status'), fn ($q) => $q->where('is_active', $request->status === 'active'))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('employees.departments.index', compact('departments'));
    }

    public function create(): View
    {
        return view('employees.departments.create', [
            'employees' => Employee::query()->orderBy('first_name')->get(['id', 'first_name', 'last_name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:20', Rule::unique('departments', 'code')],
            'description' => ['nullable', 'string', 'max:5000'],
            'head_of_department_id' => ['nullable', 'integer', Rule::exists('employees', 'id')],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $department = Department::create([
            'name' => $data['name'],
            'code' => $data['code'] ?? null,
            'description' => $data['description'] ?? null,
            'head_of_department_id' => $data['head_of_department_id'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return redirect()->route('employees.departments.show', $department)
            ->with('toasts', [['type' => 'success', 'message' => "Department {$department->name} created."]]);
    }

    public function show(Department $department): View
    {
        $department->load(['headOfDepartment', 'employees']);

        return view('employees.departments.show', compact('department'));
    }

    public function edit(Department $department): View
    {
        return view('employees.departments.edit', [
            'department' => $department,
            'employees' => Employee::query()->orderBy('first_name')->get(['id', 'first_name', 'last_name']),
        ]);
    }

    public function update(Request $request, Department $department): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:20', Rule::unique('departments', 'code')->ignore($department->id)],
            'description' => ['nullable', 'string', 'max:5000'],
            'head_of_department_id' => ['nullable', 'integer', Rule::exists('employees', 'id')],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $department->update([
            'name' => $data['name'],
            'code' => $data['code'] ?? null,
            'description' => $data['description'] ?? null,
            'head_of_department_id' => $data['head_of_department_id'] ?? null,
            'is_active' => $request->boolean('is_active', $department->is_active),
        ]);

        return back()->with('toasts', [['type' => 'success', 'message' => "Department {$department->name} updated."]]);
    }

    public function destroy(Department $department): RedirectResponse
    {
        if ($department->employees()->exists()) {
            return back()->with('toasts', [['type' => 'danger', 'message' => 'This department has employees and cannot be deleted.']]);
        }

        $department->delete();

        return redirect()->route('employees.departments.index')
            ->with('toasts', [['type' => 'success', 'message' => "Department {$department->name} deleted."]]);
    }

    public function export(Request $request): StreamedResponse
    {
        $departments = Department::query()
            ->withCount('employees')
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->when($request->filled('status'), fn ($q) => $q->where('is_active', $request->status === 'active'))
            ->orderBy('name')
            ->get();

        $rows = $departments->map(fn (Department $d) => [
            'id' => $d->id,
            'name' => $d->name,
            'code' => $d->code,
            'head' => $d->headOfDepartment?->fullName(),
            'employees' => $d->employees_count,
            'active' => $d->is_active ? 'Yes' : 'No',
        ]);

        return $request->query('format') === 'json'
            ? $this->streamJson('departments-'.now()->format('Y-m-d').'.json', $rows)
            : $this->streamCsv('departments-'.now()->format('Y-m-d').'.csv', ['ID', 'Name', 'Code', 'Head', 'Employees', 'Active'], $rows->values());
    }
}
