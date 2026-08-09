<?php

namespace App\Http\Controllers\Banking;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Support\ExportsCsv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BankAccountController extends Controller
{
    use ExportsCsv;

    public function index(Request $request): View
    {
        $accounts = BankAccount::query()
            ->withCount('transactions')
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->when($request->filled('type'), fn ($q) => $q->where('account_type', $request->type))
            ->when($request->filled('status'), fn ($q) => $q
                ->when($request->status === 'active', fn ($q) => $q->where('is_active', true))
                ->when($request->status === 'inactive', fn ($q) => $q->where('is_active', false)))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        $totalBalance = (float) BankAccount::query()->get()->sum(fn (BankAccount $a) => $a->balance());
        $cashBalance = (float) BankAccount::query()->where('account_type', 'cash')->get()->sum(fn (BankAccount $a) => $a->balance());

        return view('banking.accounts.index', compact('accounts', 'totalBalance', 'cashBalance'));
    }

    public function create(): View
    {
        return view('banking.accounts.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

        $account = BankAccount::create([
            'name' => $data['name'],
            'account_number' => $data['account_number'] ?? null,
            'bank_name' => $data['bank_name'] ?? null,
            'branch' => $data['branch'] ?? null,
            'account_type' => $data['account_type'],
            'currency' => $data['currency'],
            'opening_balance' => $data['opening_balance'],
            'is_active' => $request->boolean('is_active', true),
            'notes' => $data['notes'] ?? null,
        ]);

        return redirect()->route('banking.accounts.show', $account)
            ->with('toasts', [['type' => 'success', 'message' => "Bank account \"{$account->name}\" created."]]);
    }

    public function show(BankAccount $account): View
    {
        $account->load([
            'transactions' => fn ($q) => $q->latest('transaction_date')->limit(10),
        ]);

        return view('banking.accounts.show', compact('account'));
    }

    public function edit(BankAccount $account): View
    {
        return view('banking.accounts.edit', compact('account'));
    }

    public function update(Request $request, BankAccount $account): RedirectResponse
    {
        $data = $this->validateData($request, $account->id);

        $account->update([
            'name' => $data['name'],
            'account_number' => $data['account_number'] ?? null,
            'bank_name' => $data['bank_name'] ?? null,
            'branch' => $data['branch'] ?? null,
            'account_type' => $data['account_type'],
            'currency' => $data['currency'],
            'opening_balance' => $data['opening_balance'],
            'is_active' => $request->boolean('is_active', true),
            'notes' => $data['notes'] ?? null,
        ]);

        return back()->with('toasts', [['type' => 'success', 'message' => "Bank account \"{$account->name}\" updated."]]);
    }

    public function destroy(BankAccount $account): RedirectResponse
    {
        if ($account->transactions()->exists()) {
            return back()->with('toasts', [['type' => 'error', 'message' => 'This account still has transactions and cannot be deleted.']]);
        }

        $name = $account->name;
        $account->delete();

        return redirect()->route('banking.accounts.index')
            ->with('toasts', [['type' => 'success', 'message' => "Bank account \"{$name}\" deleted."]]);
    }

    public function export(Request $request): StreamedResponse
    {
        $accounts = BankAccount::query()
            ->withCount('transactions')
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->when($request->filled('type'), fn ($q) => $q->where('account_type', $request->type))
            ->orderBy('name')
            ->get();

        return $this->streamCsv('bank-accounts-'.now()->format('Y-m-d').'.csv', ['Name', 'Account no.', 'Bank', 'Branch', 'Type', 'Currency', 'Opening balance', 'Balance', 'Transactions', 'Status'], $accounts->map(fn (BankAccount $a) => [
            $a->name,
            $a->account_number,
            $a->bank_name,
            $a->branch,
            ucfirst($a->account_type),
            $a->currency,
            $a->opening_balance,
            $a->balance(),
            $a->transactions_count,
            $a->is_active ? 'Active' : 'Inactive',
        ]));
    }

    private function validateData(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'account_number' => ['nullable', 'string', 'max:80'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'branch' => ['nullable', 'string', 'max:255'],
            'account_type' => ['required', Rule::in(BankAccount::typeOptions())],
            'currency' => ['required', 'string', 'max:8'],
            'opening_balance' => ['required', 'numeric'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }
}
