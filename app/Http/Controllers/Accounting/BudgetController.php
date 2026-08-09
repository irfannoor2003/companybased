<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Budget;
use App\Support\ExportsCsv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BudgetController extends Controller
{
    use ExportsCsv;

    public function index(Request $request): View
    {
        $budgets = Budget::query()
            ->withCount('items')
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('year'), fn ($q) => $q->where('fiscal_year', $request->year))
            ->orderByDesc('fiscal_year')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('accounting.budgets.index', compact('budgets'));
    }

    public function create(): View
    {
        return view('accounting.budgets.create', [
            'accounts' => Account::query()->active()->whereIn('type', ['revenue', 'expense'])->orderBy('code')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'fiscal_year' => ['required', 'digits:4'],
            'currency' => ['required', 'string', 'max:8'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', Rule::in(Budget::statusOptions())],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.account_id' => ['required', 'integer'],
            'lines.*.budget_amount' => ['required', 'numeric', 'gt:0'],
        ]);

        $budget = Budget::create([
            'name' => $data['name'],
            'fiscal_year' => $data['fiscal_year'],
            'currency' => $data['currency'],
            'description' => $data['description'] ?? null,
            'status' => $data['status'],
        ]);

        foreach ($data['lines'] as $line) {
            $budget->items()->create([
                'account_id' => $line['account_id'],
                'budget_amount' => (string) $line['budget_amount'],
            ]);
        }

        return redirect()->route('accounting.budgets.show', $budget)
            ->with('toasts', [['type' => 'success', 'message' => "Budget \"{$budget->name}\" created."]]);
    }

    public function show(Budget $budget): View
    {
        $budget->load(['items.account']);

        return view('accounting.budgets.show', compact('budget'));
    }

    public function edit(Budget $budget): View
    {
        $budget->load('items');

        return view('accounting.budgets.edit', [
            'budget' => $budget,
            'accounts' => Account::query()->active()->whereIn('type', ['revenue', 'expense'])->orderBy('code')->get(),
        ]);
    }

    public function update(Request $request, Budget $budget): RedirectResponse
    {
        if ($budget->status === 'closed') {
            return back()->with('toasts', [['type' => 'danger', 'message' => 'Closed budgets cannot be edited.']]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'fiscal_year' => ['required', 'digits:4'],
            'currency' => ['required', 'string', 'max:8'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', Rule::in(Budget::statusOptions())],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.account_id' => ['required', 'integer'],
            'lines.*.budget_amount' => ['required', 'numeric', 'gt:0'],
        ]);

        $budget->update([
            'name' => $data['name'],
            'fiscal_year' => $data['fiscal_year'],
            'currency' => $data['currency'],
            'description' => $data['description'] ?? null,
            'status' => $data['status'],
        ]);

        $budget->items()->delete();
        foreach ($data['lines'] as $line) {
            $budget->items()->create([
                'account_id' => $line['account_id'],
                'budget_amount' => (string) $line['budget_amount'],
            ]);
        }

        return back()->with('toasts', [['type' => 'success', 'message' => "Budget \"{$budget->name}\" updated."]]);
    }

    public function updateStatus(Request $request, Budget $budget): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['active', 'closed'])],
        ]);

        $budget->update(['status' => $data['status']]);

        return back()->with('toasts', [['type' => 'success', 'message' => "Budget \"{$budget->name}\" marked {$data['status']}."]]);
    }

    public function destroy(Budget $budget): RedirectResponse
    {
        $budget->delete();

        return redirect()->route('accounting.budgets.index')
            ->with('toasts', [['type' => 'success', 'message' => "Budget \"{$budget->name}\" deleted."]]);
    }

    public function export(Request $request): StreamedResponse
    {
        $budgets = Budget::query()
            ->with('items.account')
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('year'), fn ($q) => $q->where('fiscal_year', $request->year))
            ->orderByDesc('fiscal_year')
            ->get();

        return $this->streamCsv('budgets-'.now()->format('Y-m-d').'.csv', ['Budget', 'Fiscal year', 'Account', 'Budgeted', 'Actual', 'Variance'], $budgets->flatMap(fn (Budget $b) => $b->items->map(fn ($i) => [
            $b->name,
            $b->fiscal_year,
            $i->account?->name,
            $i->budget_amount,
            $i->actualAmount(),
            round($i->actualAmount() - (float) $i->budget_amount, 2),
        ])));
    }
}