<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\ExpenseClaim;
use App\Support\ExportsCsv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExpenseClaimController extends Controller
{
    use ExportsCsv;

    public function index(Request $request): View
    {
        $claims = ExpenseClaim::query()
            ->with('reviewer')
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('type'), fn ($q) => $q->where('expense_type', $request->type))
            ->when($request->filled('from'), fn ($q) => $q->where('expense_date', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->where('expense_date', '<=', $request->to))
            ->orderByDesc('expense_date')
            ->paginate(20)
            ->withQueryString();

        $pendingTotal = round((float) ExpenseClaim::query()->where('status', 'pending')->sum('amount'), 2);
        $approvedTotal = round((float) ExpenseClaim::query()->where('status', 'approved')->sum('amount'), 2);

        return view('accounting.expense_claims.index', compact('claims', 'pendingTotal', 'approvedTotal'));
    }

    public function create(): View
    {
        return view('accounting.expense_claims.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

        $claim = ExpenseClaim::create([
            'number' => next_document_number('expense_claim', 'EC'),
            'employee_name' => $data['employee_name'],
            'expense_date' => $data['expense_date'],
            'expense_type' => $data['expense_type'],
            'merchant' => $data['merchant'] ?? null,
            'amount' => $data['amount'],
            'currency' => $data['currency'],
            'status' => 'pending',
            'notes' => $data['notes'] ?? null,
        ]);

        return redirect()->route('accounting.expense_claims.show', $claim)
            ->with('toasts', [['type' => 'success', 'message' => "Expense claim {$claim->number} created."]]);
    }

    public function show(ExpenseClaim $claim): View
    {
        return view('accounting.expense_claims.show', compact('claim'));
    }

    public function edit(ExpenseClaim $claim): View
    {
        return view('accounting.expense_claims.edit', compact('claim'));
    }

    public function update(Request $request, ExpenseClaim $claim): RedirectResponse
    {
        if ($claim->status !== 'pending') {
            return back()->with('toasts', [['type' => 'danger', 'message' => 'Only pending claims can be edited.']]);
        }

        $data = $this->validateData($request);

        $claim->update([
            'employee_name' => $data['employee_name'],
            'expense_date' => $data['expense_date'],
            'expense_type' => $data['expense_type'],
            'merchant' => $data['merchant'] ?? null,
            'amount' => $data['amount'],
            'currency' => $data['currency'],
            'notes' => $data['notes'] ?? null,
        ]);

        return back()->with('toasts', [['type' => 'success', 'message' => "Expense claim {$claim->number} updated."]]);
    }

    public function updateStatus(Request $request, ExpenseClaim $claim): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['approved', 'rejected', 'reimbursed'])],
        ]);

        $current = $claim->status;

        $updates = ['status' => $data['status']];

        if ($data['status'] === 'approved' && $current === 'pending') {
            $updates['reviewed_by'] = auth()->id();
            $updates['reviewed_at'] = now();
        }

        if ($data['status'] === 'reimbursed') {
            if ($current === 'approved') {
                $updates['reimbursed_at'] = now();
            } else {
                $updates['reviewed_by'] = auth()->id();
                $updates['reviewed_at'] = now();
                $updates['reimbursed_at'] = now();
            }
        }

        $claim->update($updates);

        return back()->with('toasts', [['type' => 'success', 'message' => "Expense claim {$claim->number} marked {$data['status']}."]]);
    }

    public function destroy(ExpenseClaim $claim): RedirectResponse
    {
        $claim->delete();

        return redirect()->route('accounting.expense_claims.index')
            ->with('toasts', [['type' => 'success', 'message' => "Expense claim {$claim->number} deleted."]]);
    }

    public function export(Request $request): StreamedResponse
    {
        $claims = ExpenseClaim::query()
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('type'), fn ($q) => $q->where('expense_type', $request->type))
            ->when($request->filled('from'), fn ($q) => $q->where('expense_date', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->where('expense_date', '<=', $request->to))
            ->orderByDesc('expense_date')
            ->get();

        return $this->streamCsv('expense-claims-'.now()->format('Y-m-d').'.csv', ['Number', 'Date', 'Employee', 'Type', 'Merchant', 'Amount', 'Status'], $claims->map(fn (ExpenseClaim $c) => [
            $c->number,
            $c->expense_date->format('Y-m-d'),
            $c->employee_name,
            ucfirst($c->expense_type),
            $c->merchant,
            $c->amount,
            ucfirst($c->status),
        ]));
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'employee_name' => ['required', 'string', 'max:255'],
            'expense_date' => ['required', 'date'],
            'expense_type' => ['required', Rule::in(ExpenseClaim::typeOptions())],
            'merchant' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'currency' => ['required', 'string', 'max:8'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }
}