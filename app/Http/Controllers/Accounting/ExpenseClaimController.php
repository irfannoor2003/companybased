<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\ExpenseClaim;
use App\Services\NotificationService;
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

        app(NotificationService::class)->notifyStaff(
            'accounting.expense_claims.view',
            'Expense claim submitted',
            'Claim '.$claim->number.' from '.$claim->employee_name.' for '.money($claim->amount, $claim->currency).' needs approval.',
            'info',
            route('accounting.expense_claims.show', $claim),
            auth()->id(),
        );

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

    /**
     * Raise the claim for approval.
     *
     * The old updateStatus() endpoint took a target status straight from the request
 * and let the caller jump to `reimbursed`. It also stamped reviewed_by as a side
 * effect when reimbursing an unapproved claim -- so one request could pay out
 * money that nobody had approved, approved by the same person, with a timestamp
 * on it. Every transition now goes through the shared approval engine instead.
 */
public function submit(Request $request, ExpenseClaim $claim): RedirectResponse
{
    try {
        $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);

        $claim->submitForApproval($request->user(), 1, $request->input('comment'));
    } catch (\RuntimeException $e) {
        return back()->with('toasts', [['type' => 'danger', 'message' => $e->getMessage()]]);
    }

    return back()->with('toasts', [[
        'type' => 'success',
        'message' => "Expense claim {$claim->number} submitted for approval.",
    ]]);
}

public function approve(Request $request, ExpenseClaim $claim): RedirectResponse
{
    try {
        $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);

        $claim->approve($request->user(), $request->input('comment'));

        // reviewed_by/reviewed_at are the legacy summary columns the list view
        // reads, kept in step with the real trail rather than replacing it.
        $claim->update(['reviewed_by' => $request->id(), 'reviewed_at' => now()]);
    } catch (\RuntimeException $e) {
        return back()->with('toasts', [['type' => 'danger', 'message' => $e->getMessage()]]);
    }

    return back()->with('toasts', [[
        'type' => 'success',
        'message' => "Expense claim {$claim->number} approved.",
    ]]);
}

public function reject(Request $request, ExpenseClaim $claim): RedirectResponse
{
    try {
        $data = $request->validate([
            'comment' => ['required', 'string', 'max:2000'],
        ]);

        $claim->reject($request->user(), $data['comment']);

        $claim->update(['reviewed_by' => $request->id(), 'reviewed_at' => now()]);
    } catch (\RuntimeException $e) {
        return back()->with('toasts', [['type' => 'danger', 'message' => $e->getMessage()]]);
    }

    return back()->with('toasts', [[
        'type' => 'success',
        'message' => "Expense claim {$claim->number} rejected. It can be corrected and resubmitted.",
    ]]);
}

public function withdraw(Request $request, ExpenseClaim $claim): RedirectResponse
{
    try {
        $claim->withdrawApproval($request->user());
    } catch (\RuntimeException $e) {
        return back()->with('toasts', [['type' => 'danger', 'message' => $e->getMessage()]]);
    }

    return back()->with('toasts', [[
        'type' => 'success',
        'message' => "Approval request for {$claim->number} withdrawn.",
    ]]);
}

/**
 * Pay out an approved claim.
 *
 * Refuses one that was never approved. This is the transition the old endpoint
 * allowed unconditionally, and it is the one that costs real money.
 */
public function reimburse(Request $request, ExpenseClaim $claim): RedirectResponse
{
    try {
        $claim->markReimbursed($request->user());
    } catch (\RuntimeException $e) {
        return back()->with('toasts', [['type' => 'danger', 'message' => $e->getMessage()]]);
    }

    return back()->with('toasts', [[
        'type' => 'success',
        'message' => "Expense claim {$claim->number} marked as reimbursed.",
    ]]);
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
