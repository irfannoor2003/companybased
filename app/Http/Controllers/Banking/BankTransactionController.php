<?php

namespace App\Http\Controllers\Banking;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Support\ExportsCsv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BankTransactionController extends Controller
{
    use ExportsCsv;

    public function index(Request $request): View
    {
        $transactions = BankTransaction::query()
            ->with(['account'])
            ->when($request->filled('search'), fn ($q) => $q
                ->where(fn ($q) => $q->where('number', 'like', "%{$request->search}%")
                    ->orWhere('counterparty', 'like', "%{$request->search}%")
                    ->orWhere('description', 'like', "%{$request->search}%")
                    ->orWhere('reference', 'like', "%{$request->search}%")))
            ->when($request->filled('account'), fn ($q) => $q->where('bank_account_id', $request->account))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('transaction_date', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('transaction_date', '<=', $request->to))
            ->latest('transaction_date')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        $accounts = BankAccount::query()->orderBy('name')->get();

        return view('banking.transactions.index', compact('transactions', 'accounts'));
    }

    public function create(Request $request): View
    {
        $accounts = BankAccount::query()->orderBy('name')->get();
        $presetAccount = $request->integer('account') ?: null;

        return view('banking.transactions.create', compact('accounts', 'presetAccount'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

        $transaction = BankTransaction::create([
            'bank_account_id' => $data['bank_account_id'],
            'number' => next_document_number('bank_transaction', 'BT'),
            'transaction_date' => $data['transaction_date'],
            'type' => $data['type'],
            'amount' => $data['amount'],
            'counterparty' => $data['counterparty'] ?? null,
            'description' => $data['description'] ?? null,
            'reference' => $data['reference'] ?? null,
            'is_reconciled' => false,
        ]);

        return redirect()->route('banking.transactions.edit', $transaction)
            ->with('toasts', [['type' => 'success', 'message' => "Transaction {$transaction->number} recorded."]]);
    }

    public function edit(BankTransaction $transaction): View
    {
        $transaction->load('account');
        $accounts = BankAccount::query()->orderBy('name')->get();

        return view('banking.transactions.edit', compact('transaction', 'accounts'));
    }

    public function update(Request $request, BankTransaction $transaction): RedirectResponse
    {
        $data = $this->validateData($request, $transaction->id);

        $transaction->update([
            'bank_account_id' => $data['bank_account_id'],
            'transaction_date' => $data['transaction_date'],
            'type' => $data['type'],
            'amount' => $data['amount'],
            'counterparty' => $data['counterparty'] ?? null,
            'description' => $data['description'] ?? null,
            'reference' => $data['reference'] ?? null,
        ]);

        return back()->with('toasts', [['type' => 'success', 'message' => "Transaction {$transaction->number} updated."]]);
    }

    public function destroy(BankTransaction $transaction): RedirectResponse
    {
        $number = $transaction->number;
        $transaction->delete();

        return back()->with('toasts', [['type' => 'success', 'message' => "Transaction {$number} deleted."]]);
    }

    public function export(Request $request): StreamedResponse
    {
        $transactions = BankTransaction::query()
            ->with(['account'])
            ->when($request->filled('search'), fn ($q) => $q
                ->where(fn ($q) => $q->where('number', 'like', "%{$request->search}%")
                    ->orWhere('counterparty', 'like', "%{$request->search}%")
                    ->orWhere('description', 'like', "%{$request->search}%")
                    ->orWhere('reference', 'like', "%{$request->search}%")))
            ->when($request->filled('account'), fn ($q) => $q->where('bank_account_id', $request->account))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('transaction_date', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('transaction_date', '<=', $request->to))
            ->latest('transaction_date')
            ->latest('id')
            ->get();

        return $this->streamCsv('bank-transactions-'.now()->format('Y-m-d').'.csv', ['Number', 'Date', 'Account', 'Type', 'Amount', 'Counterparty', 'Description', 'Reference', 'Reconciled'], $transactions->map(fn (BankTransaction $t) => [
            $t->number,
            $t->transaction_date?->format('Y-m-d'),
            $t->account?->name,
            ucfirst(str_replace('_', ' ', $t->type)),
            $t->amount,
            $t->counterparty,
            $t->description,
            $t->reference,
            $t->is_reconciled ? 'Yes' : 'No',
        ]));
    }

    private function validateData(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'bank_account_id' => ['required', 'exists:bank_accounts,id'],
            'transaction_date' => ['required', 'date'],
            'type' => ['required', Rule::in(BankTransaction::typeOptions())],
            'amount' => ['required', 'numeric', 'gt:0'],
            'counterparty' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'reference' => ['nullable', 'string', 'max:120'],
        ]);
    }
}
