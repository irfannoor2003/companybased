<?php

namespace App\Http\Controllers\Banking;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Reconciliation;
use App\Models\ReconciliationItem;
use App\Support\ExportsCsv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReconciliationController extends Controller
{
    use ExportsCsv;

    public function index(Request $request): View
    {
        $reconciliations = Reconciliation::query()
            ->with(['account'])
            ->withCount('items')
            ->when($request->filled('search'), fn ($q) => $q->where('number', 'like', "%{$request->search}%"))
            ->when($request->filled('account'), fn ($q) => $q->where('bank_account_id', $request->account))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->latest('statement_date')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        $accounts = BankAccount::query()->orderBy('name')->get();

        return view('banking.reconciliations.index', compact('reconciliations', 'accounts'));
    }

    public function create(): View
    {
        $accounts = BankAccount::query()->orderBy('name')->get();

        return view('banking.reconciliations.create', compact('accounts'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

        $account = BankAccount::query()->findOrFail($data['bank_account_id']);

        $reconciliation = Reconciliation::create([
            'number' => next_document_number('reconciliation', 'REC'),
            'bank_account_id' => $account->id,
            'statement_date' => $data['statement_date'],
            'opening_balance' => $account->opening_balance,
            'statement_ending_balance' => $data['statement_ending_balance'],
            'status' => 'draft',
            'notes' => $data['notes'] ?? null,
        ]);

        $this->syncItems($reconciliation, $this->inScopeTransactions($account, $data['statement_date']), []);

        return redirect()->route('banking.reconciliations.edit', $reconciliation)
            ->with('toasts', [['type' => 'success', 'message' => "Reconciliation {$reconciliation->number} started."]]);
    }

    public function edit(Reconciliation $reconciliation): View
    {
        $reconciliation->load(['account', 'items']);
        $accounts = BankAccount::query()->orderBy('name')->get();

        $transactions = $this->inScopeTransactions($reconciliation->account, $reconciliation->statement_date)
            ->map(function (BankTransaction $t) use ($reconciliation) {
                $t->is_cleared = (bool) $reconciliation->items->firstWhere('bank_transaction_id', $t->id)?->is_cleared;

                return $t;
            });

        $running = (float) $reconciliation->opening_balance;
        $transactions->each(function (BankTransaction $t) use (&$running) {
            $running += $t->signedAmount();
            $t->running_balance = $running;
        });

        return view('banking.reconciliations.edit', compact('reconciliation', 'accounts', 'transactions'));
    }

    public function update(Request $request, Reconciliation $reconciliation): RedirectResponse
    {
        if ($reconciliation->isCompleted()) {
            return back()->with('toasts', [['type' => 'danger', 'message' => 'Completed reconciliations are locked.']]);
        }

        $data = $this->validateData($request, $reconciliation->id);

        $account = BankAccount::query()->findOrFail($data['bank_account_id']);

        $reconciliation->update([
            'bank_account_id' => $account->id,
            'statement_date' => $data['statement_date'],
            'opening_balance' => $account->opening_balance,
            'statement_ending_balance' => $data['statement_ending_balance'],
            'notes' => $data['notes'] ?? null,
        ]);

        $this->syncItems($reconciliation, $this->inScopeTransactions($account, $data['statement_date']), $request->input('cleared', []));

        return back()->with('toasts', [['type' => 'success', 'message' => "Reconciliation {$reconciliation->number} updated."]]);
    }

    public function destroy(Reconciliation $reconciliation): RedirectResponse
    {
        if ($reconciliation->isCompleted()) {
            return back()->with('toasts', [['type' => 'danger', 'message' => 'Completed reconciliations cannot be deleted; cancel them first.']]);
        }

        $number = $reconciliation->number;
        $reconciliation->delete();

        return redirect()->route('banking.reconciliations.index')
            ->with('toasts', [['type' => 'success', 'message' => "Reconciliation {$number} deleted."]]);
    }

    public function updateStatus(Request $request, Reconciliation $reconciliation): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(Reconciliation::statusOptions())],
        ]);

        $toStatus = $data['status'];

        if ($toStatus === $reconciliation->status) {
            return back()->with('toasts', [['type' => 'danger', 'message' => 'Reconciliation is already in that status.']]);
        }

        if ($toStatus === 'completed') {
            if (round($reconciliation->difference(), 2) !== 0.0) {
                return back()->with('toasts', [['type' => 'danger', 'message' => 'Cannot complete: the statement does not balance (difference '.money($reconciliation->difference(), $reconciliation->account?->currency).').']]);
            }

            $reconciliation->items()->where('is_cleared', true)->with('transaction')->get()
                ->each(function (ReconciliationItem $item) {
                    if ($item->transaction) {
                        $item->transaction->update(['is_reconciled' => true, 'reconciled_at' => now()]);
                    }
                });

            $reconciliation->update(['status' => 'completed']);
        } elseif ($toStatus === 'cancelled' && $reconciliation->isCompleted()) {
            $reconciliation->items()->where('is_cleared', true)->with('transaction')->get()
                ->each(function (ReconciliationItem $item) {
                    if ($item->transaction) {
                        $item->transaction->update(['is_reconciled' => false, 'reconciled_at' => null]);
                    }
                });

            $reconciliation->update(['status' => 'cancelled']);
        } else {
            $reconciliation->update(['status' => $toStatus]);
        }

        return back()->with('toasts', [['type' => 'success', 'message' => "Reconciliation {$reconciliation->number} marked as {$toStatus}."]]);
    }

    public function export(Request $request): StreamedResponse
    {
        $reconciliations = Reconciliation::query()
            ->with(['account'])
            ->when($request->filled('search'), fn ($q) => $q->where('number', 'like', "%{$request->search}%"))
            ->when($request->filled('account'), fn ($q) => $q->where('bank_account_id', $request->account))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->latest('statement_date')
            ->latest('id')
            ->get();

        return $this->streamCsv('reconciliations-'.now()->format('Y-m-d').'.csv', ['Number', 'Account', 'Statement date', 'Opening', 'Book balance', 'Statement ending', 'Difference', 'Status'], $reconciliations->map(fn (Reconciliation $r) => [
            $r->number,
            $r->account?->name,
            $r->statement_date?->format('Y-m-d'),
            $r->opening_balance,
            $r->bookBalance(),
            $r->statement_ending_balance,
            $r->difference(),
            ucfirst($r->status),
        ]));
    }

    private function inScopeTransactions(BankAccount $account, string $statementDate)
    {
        return $account->transactions()
            ->whereDate('transaction_date', '<=', $statementDate)
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, BankTransaction>  $transactions
     * @param  array<string, int>  $cleared
     */
    private function syncItems(Reconciliation $reconciliation, $transactions, array $cleared): void
    {
        foreach ($transactions as $transaction) {
            ReconciliationItem::updateOrCreate(
                ['reconciliation_id' => $reconciliation->id, 'bank_transaction_id' => $transaction->id],
                ['is_cleared' => array_key_exists($transaction->id, $cleared) ? (int) $cleared[$transaction->id] === 1 : true],
            );
        }

        $inScope = $transactions->pluck('id');
        ReconciliationItem::query()
            ->where('reconciliation_id', $reconciliation->id)
            ->whereNotIn('bank_transaction_id', $inScope)
            ->delete();
    }

    private function validateData(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'bank_account_id' => ['required', 'exists:bank_accounts,id'],
            'statement_date' => ['required', 'date'],
            'statement_ending_balance' => ['required', 'numeric'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }
}
