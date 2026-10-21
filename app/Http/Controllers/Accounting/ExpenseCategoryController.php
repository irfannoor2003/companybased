<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\ExpenseCategory;
use App\Support\ExportsCsv;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExpenseCategoryController extends Controller
{
    use ExportsCsv;

    public function index(Request $request): View
    {
        $categories = ExpenseCategory::query()
            ->with('account')
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', "%{$request->search}%"))
            ->when($request->filled('status'), fn ($q) => $q->when($request->status === 'active', fn ($q) => $q->where('is_active', true))
                ->when($request->status === 'inactive', fn ($q) => $q->where('is_active', false)))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('accounting.expense_categories.index', compact('categories'));
    }

    public function create(): View
    {
        $accounts = Account::query()->where('type', 'expense')->where('is_active', true)->orderBy('code')->get();

        return view('accounting.expense_categories.create', compact('accounts'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

        try {
            $category = ExpenseCategory::create([
                'name' => $data['name'],
                'slug' => unique_slug(ExpenseCategory::class, $data['name']),
                'description' => $data['description'] ?? null,
                'account_id' => $data['account_id'] ?? null,
                'is_active' => $request->boolean('is_active', true),
            ]);
        } catch (QueryException $e) {
            if (isset($e->errorInfo[1]) && $e->errorInfo[1] == 1062) {
                return back()->withInput()
                    ->with('toasts', [['type' => 'danger', 'message' => 'A category with the name "'.$data['name'].'" already exists. Please choose a different name.']]);
            }
            throw $e;
        }

        return redirect()->route('accounting.expense_categories.index')
            ->with('toasts', [['type' => 'success', 'message' => "Expense category \"{$category->name}\" created."]]);
    }

    public function edit(ExpenseCategory $category): View
    {
        $accounts = Account::query()->where('type', 'expense')->where('is_active', true)->orderBy('code')->get();

        return view('accounting.expense_categories.edit', compact('category', 'accounts'));
    }

    public function update(Request $request, ExpenseCategory $category): RedirectResponse
    {
        $data = $this->validateData($request, $category->id);

        $category->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'account_id' => $data['account_id'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return back()->with('toasts', [['type' => 'success', 'message' => "Expense category \"{$category->name}\" updated."]]);
    }

    public function destroy(ExpenseCategory $category): RedirectResponse
    {
        if ($category->account()->exists() === false && false) {
        }

        if (\App\Models\PurchaseInvoiceItem::where('expense_category_id', $category->id)->exists()) {
            return back()->with('toasts', [['type' => 'error', 'message' => 'Category is assigned to bill lines and cannot be deleted.']]);
        }

        $name = $category->name;
        $category->delete();

        return redirect()->route('accounting.expense_categories.index')
            ->with('toasts', [['type' => 'success', 'message' => "Expense category \"{$name}\" deleted."]]);
    }

    public function export(Request $request): StreamedResponse
    {
        $categories = ExpenseCategory::query()
            ->with('account')
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', "%{$request->search}%"))
            ->orderBy('name')
            ->get();

        return $this->streamCsv('expense-categories-'.now()->format('Y-m-d').'.csv', ['ID', 'Name', 'Account', 'Description', 'Status'], $categories->map(fn (ExpenseCategory $c) => [
            $c->id,
            $c->name,
            $c->account?->code.' — '.$c->account?->name,
            $c->description,
            $c->is_active ? 'Active' : 'Inactive',
        ]));
    }

    private function validateData(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('expense_categories', 'name')->ignore($ignoreId)],
            'account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ]);
    }
}
