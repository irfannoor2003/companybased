<?php

namespace App\Http\Controllers\Banking;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\BankTransfer;
use App\Support\BankLedger;
use App\Support\ExportsCsv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BankTransferController extends Controller
{
    use ExportsCsv;

    public function index(Request $request): View
    {
        $transfers = BankTransfer::query()
            ->with(['fromAccount', 'toAccount'])
            ->when($request->filled('search'), fn ($q) => $q->where('number', 'like', "%{$request->search}%"))
            ->when($request->filled('account'), fn ($q) => $q->where(fn ($q) => $q
                ->where('from_account_id', $request->account)
                ->orWhere('to_account_id', $request->account)))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->latest('transfer_date')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        $accounts = BankAccount::query()->orderBy('name')->get();

        return view('banking.transfers.index', compact('transfers', 'accounts'));
    }

    public function create(): View
    {
        $accounts = BankAccount::query()->orderBy('name')->get();

        return view('banking.transfers.create', compact('accounts'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

        $transfer = BankTransfer::create([
            'number' => next_document_number('bank_transfer', 'XFR'),
            'transfer_date' => $data['transfer_date'],
            'from_account_id' => $data['from_account_id'],
            'to_account_id' => $data['to_account_id'],
            'amount' => $data['amount'],
            'description' => $data['description'] ?? null,
            'status' => 'draft',
        ]);

        return redirect()->route('banking.transfers.edit', $transfer)
            ->with('toasts', [['type' => 'success', 'message' => "Transfer {$transfer->number} created."]]);
    }

    public function edit(BankTransfer $transfer): View
    {
        $transfer->load(['fromAccount', 'toAccount', 'transactions']);
        $accounts = BankAccount::query()->orderBy('name')->get();

        return view('banking.transfers.edit', compact('transfer', 'accounts'));
    }

    public function update(Request $request, BankTransfer $transfer): RedirectResponse
    {
        if ($transfer->isCompleted()) {
            return back()->with('toasts', [['type' => 'danger', 'message' => 'Completed transfers are locked.']]);
        }

        $data = $this->validateData($request, $transfer->id);

        $transfer->update([
            'transfer_date' => $data['transfer_date'],
            'from_account_id' => $data['from_account_id'],
            'to_account_id' => $data['to_account_id'],
            'amount' => $data['amount'],
            'description' => $data['description'] ?? null,
        ]);

        return back()->with('toasts', [['type' => 'success', 'message' => "Transfer {$transfer->number} updated."]]);
    }

    public function destroy(BankTransfer $transfer): RedirectResponse
    {
        if ($transfer->isCompleted()) {
            return back()->with('toasts', [['type' => 'danger', 'message' => 'Completed transfers cannot be deleted; cancel them first.']]);
        }

        $number = $transfer->number;
        $transfer->delete();

        return redirect()->route('banking.transfers.index')
            ->with('toasts', [['type' => 'success', 'message' => "Transfer {$number} deleted."]]);
    }

    public function updateStatus(Request $request, BankTransfer $transfer): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(BankTransfer::statusOptions())],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $toStatus = $data['status'];

        if ($toStatus === $transfer->status) {
            return back()->with('toasts', [['type' => 'danger', 'message' => 'Transfer is already in that status.']]);
        }

        if ($toStatus === 'completed') {
            if (! $transfer->from_account_id || ! $transfer->to_account_id || $transfer->from_account_id === $transfer->to_account_id) {
                return back()->with('toasts', [['type' => 'danger', 'message' => 'Choose two different accounts before completing the transfer.']]);
            }

            $source = BankAccount::query()->find($transfer->from_account_id);

            if ($source && $source->balance() < (float) $transfer->amount) {
                return back()->with('toasts', [['type' => 'danger', 'message' => 'Insufficient balance on the source account for this transfer.']]);
            }

            BankLedger::postTransfer($transfer);
            $transfer->update(['status' => 'completed', 'completed_at' => now()]);
        } elseif ($toStatus === 'cancelled' && $transfer->isCompleted()) {
            BankLedger::reverseTransfer($transfer);
            $transfer->update(['status' => 'cancelled', 'completed_at' => null]);
        } else {
            $transfer->update(['status' => $toStatus]);
        }

        return back()->with('toasts', [['type' => 'success', 'message' => "Transfer {$transfer->number} marked as {$toStatus}."]]);
    }

    public function export(Request $request): StreamedResponse
    {
        $transfers = BankTransfer::query()
            ->with(['fromAccount', 'toAccount'])
            ->when($request->filled('search'), fn ($q) => $q->where('number', 'like', "%{$request->search}%"))
            ->when($request->filled('account'), fn ($q) => $q->where(fn ($q) => $q
                ->where('from_account_id', $request->account)
                ->orWhere('to_account_id', $request->account)))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->latest('transfer_date')
            ->latest('id')
            ->get();

        return $this->streamCsv('bank-transfers-'.now()->format('Y-m-d').'.csv', ['Number', 'Date', 'From', 'To', 'Amount', 'Description', 'Status'], $transfers->map(fn (BankTransfer $t) => [
            $t->number,
            $t->transfer_date?->format('Y-m-d'),
            $t->fromAccount?->name,
            $t->toAccount?->name,
            $t->amount,
            $t->description,
            ucfirst($t->status),
        ]));
    }

    private function validateData(Request $request, ?int $ignoreId = null): array
    {
        $rules = [
            'transfer_date' => ['required', 'date'],
            'from_account_id' => ['required', 'exists:bank_accounts,id'],
            'to_account_id' => ['required', 'exists:bank_accounts,id', 'different:from_account_id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];

        return $request->validate($rules);
    }
}
