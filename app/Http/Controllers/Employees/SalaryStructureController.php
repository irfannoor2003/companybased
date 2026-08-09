<?php

namespace App\Http\Controllers\Employees;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\SalaryStructure;
use App\Support\ExportsCsv;
use App\Support\ExportsJson;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SalaryStructureController extends Controller
{
    use ExportsCsv;
    use ExportsJson;

    public function index(Request $request): View
    {
        $structures = SalaryStructure::query()
            ->with('employee.department')
            ->when($request->filled('employee_id'), fn ($q) => $q->where('employee_id', $request->employee_id))
            ->when($request->filled('active'), fn ($q) => $q->where('is_active', $request->boolean('active')), fn ($q) => $q->orderByRaw('is_active DESC'))
            ->orderByDesc('effective_from')
            ->paginate(20)
            ->withQueryString();

        $employees = Employee::query()->orderBy('first_name')->get(['id', 'first_name', 'last_name']);

        $monthlyGross = round((float) SalaryStructure::query()->where('is_active', true)->get()->sum(fn (SalaryStructure $s) => $s->gross()), 2);

        return view('employees.salary_structures.index', compact('structures', 'employees', 'monthlyGross'));
    }

    public function create(): View
    {
        return view('employees.salary_structures.create', [
            'employees' => Employee::query()->orderBy('first_name')->get(['id', 'first_name', 'last_name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

        SalaryStructure::query()->where('employee_id', $data['employee_id'])->where('is_active', true)
            ->update(['is_active' => false]);

        $structure = SalaryStructure::create([
            'employee_id' => $data['employee_id'],
            'effective_from' => $data['effective_from'],
            'basic_salary' => (string) $data['basic_salary'],
            'housing_allowance' => (string) $data['housing_allowance'],
            'transport_allowance' => (string) $data['transport_allowance'],
            'other_allowance' => (string) $data['other_allowance'],
            'is_active' => true,
            'notes' => $data['notes'] ?? null,
        ]);

        return redirect()->route('employees.salary_structures.index')
            ->with('toasts', [['type' => 'success', 'message' => 'Salary structure created (gross '.money($structure->gross()).').']]);
    }

    public function edit(SalaryStructure $structure): View
    {
        return view('employees.salary_structures.edit', [
            'structure' => $structure,
            'employees' => Employee::query()->orderBy('first_name')->get(['id', 'first_name', 'last_name']),
        ]);
    }

    public function update(Request $request, SalaryStructure $structure): RedirectResponse
    {
        $data = $this->validateData($request, $structure->employee_id);

        $structure->update([
            'employee_id' => $data['employee_id'],
            'effective_from' => $data['effective_from'],
            'basic_salary' => (string) $data['basic_salary'],
            'housing_allowance' => (string) $data['housing_allowance'],
            'transport_allowance' => (string) $data['transport_allowance'],
            'other_allowance' => (string) $data['other_allowance'],
            'is_active' => $structure->employee_id === $data['employee_id'] ? $structure->is_active : false,
            'notes' => $data['notes'] ?? null,
        ]);

        return back()->with('toasts', [['type' => 'success', 'message' => 'Salary structure updated.']]);
    }

    public function destroy(SalaryStructure $structure): RedirectResponse
    {
        $structure->delete();

        return redirect()->route('employees.salary_structures.index')
            ->with('toasts', [['type' => 'success', 'message' => 'Salary structure deleted.']]);
    }

    public function export(Request $request): StreamedResponse
    {
        $structures = SalaryStructure::query()
            ->with('employee.department')
            ->when($request->filled('employee_id'), fn ($q) => $q->where('employee_id', $request->employee_id))
            ->orderByDesc('effective_from')
            ->get();

        $rows = $structures->map(fn (SalaryStructure $s) => [
            'employee_code' => $s->employee?->employee_code,
            'employee' => $s->employee?->fullName(),
            'effective_from' => $s->effective_from->format('Y-m-d'),
            'basic_salary' => $s->basic_salary,
            'housing_allowance' => $s->housing_allowance,
            'transport_allowance' => $s->transport_allowance,
            'other_allowance' => $s->other_allowance,
            'gross' => $s->gross(),
            'active' => $s->is_active ? 'Yes' : 'No',
        ]);

        $filename = 'salary-structures-'.now()->format('Y-m-d').'.'.($request->query('format') === 'json' ? 'json' : 'csv');

        return $request->query('format') === 'json'
            ? $this->streamJson($filename, $rows)
            : $this->streamCsv($filename, ['Code', 'Employee', 'Effective from', 'Basic', 'Housing', 'Transport', 'Other', 'Gross', 'Active'], $rows->values());
    }

    private function validateData(Request $request, ?int $ignoreEmployeeId = null): array
    {
        return $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')],
            'effective_from' => ['required', 'date'],
            'basic_salary' => ['required', 'numeric', 'min:0'],
            'housing_allowance' => ['nullable', 'numeric', 'min:0'],
            'transport_allowance' => ['nullable', 'numeric', 'min:0'],
            'other_allowance' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }
}