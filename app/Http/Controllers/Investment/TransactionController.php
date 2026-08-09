<?php

namespace App\Http\Controllers\Investment;

use App\Http\Controllers\Controller;
use App\Models\Investment;
use App\Models\InvestmentTransaction;
use App\Support\ExportsCsv;
use App\Support\ExportsJson;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TransactionController extends Controller
{
    use ExportsCsv;
    use ExportsJson;

    public function index(Request $request): View
    {
        $transactions = InvestmentTransaction::query()
            ->with('investment')
            ->when($request->filled('search'), function ($q) use ($request) {
                $q->whereHas('investment', fn ($inv) => $inv->where('name', 'like', "%{$request->search}%")->orWhere('code', 'like', "%{$request->search}%"));
            })
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->when($request->filled('investment_id'), fn ($q) => $q->where('investment_id', $request->investment_id))
            ->when($request->filled('from'), fn ($q) => $q->where('transaction_date', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->where('transaction_date', '<=', $request->to))
            ->orderByDesc('transaction_date')
            ->paginate(20)
            ->withQueryString();

        $investments = Investment::query()->orderBy('name')->get();

        return view('investments.transactions.index', compact('transactions', 'investments'));
    }

    public function create(): View
    {
        $investments = Investment::query()->where('status', '!=', 'sold')->orderBy('name')->get();

        return view('investments.transactions.create', compact('investments'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

        $total = round((float) $data['quantity'] * (float) $data['unit_price'] + (float) $data['fees'], 2);

        $transaction = InvestmentTransaction::create([
            'investment_id' => $data['investment_id'],
            'transaction_date' => $data['transaction_date'],
            'type' => $data['type'],
            'quantity' => (string) $data['quantity'],
            'unit_price' => (string) $data['unit_price'],
            'fees' => (string) $data['fees'],
            'total' => (string) $total,
            'notes' => $data['notes'] ?? null,
        ]);

        if ($data['type'] === 'sell') {
            Investment::query()->where('id', $data['investment_id'])->update(['status' => 'sold']);
        }

        return redirect()->route('investments.transactions.index')
            ->with('toasts', [['type' => 'success', 'message' => strtoupper($data['type']).' transaction recorded.']]);
    }

    public function edit(InvestmentTransaction $transaction): View
    {
        $investments = Investment::query()->orderBy('name')->get();

        return view('investments.transactions.edit', compact('transaction', 'investments'));
    }

    public function update(Request $request, InvestmentTransaction $transaction): RedirectResponse
    {
        $data = $this->validateData($request);

        $total = round((float) $data['quantity'] * (float) $data['unit_price'] + (float) $data['fees'], 2);

        $transaction->update([
            'investment_id' => $data['investment_id'],
            'transaction_date' => $data['transaction_date'],
            'type' => $data['type'],
            'quantity' => (string) $data['quantity'],
            'unit_price' => (string) $data['unit_price'],
            'fees' => (string) $data['fees'],
            'total' => (string) $total,
            'notes' => $data['notes'] ?? null,
        ]);

        return back()->with('toasts', [['type' => 'success', 'message' => 'Transaction updated.']]);
    }

    public function destroy(InvestmentTransaction $transaction): RedirectResponse
    {
        $investmentId = $transaction->investment_id;
        $transaction->delete();

        Investment::query()
            ->where('id', $investmentId)
            ->where('status', 'sold')
            ->update(['status' => 'active']);

        return redirect()->route('investments.transactions.index')
            ->with('toasts', [['type' => 'success', 'message' => 'Transaction deleted.']]);
    }

    public function export(Request $request): StreamedResponse
    {
        $transactions = InvestmentTransaction::query()
            ->with('investment')
            ->when($request->filled('search'), function ($q) use ($request) {
                $q->whereHas('investment', fn ($inv) => $inv->where('name', 'like', "%{$request->search}%")->orWhere('code', 'like', "%{$request->search}%"));
            })
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->when($request->filled('investment_id'), fn ($q) => $q->where('investment_id', $request->investment_id))
            ->when($request->filled('from'), fn ($q) => $q->where('transaction_date', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->where('transaction_date', '<=', $request->to))
            ->orderByDesc('transaction_date')
            ->get();

        $rows = $transactions->map(fn (InvestmentTransaction $t) => [
            'date' => $t->transaction_date?->format('Y-m-d'),
            'investment_code' => $t->investment?->code,
            'investment_name' => $t->investment?->name,
            'type' => ucfirst($t->type),
            'quantity' => $t->quantity,
            'unit_price' => $t->unit_price,
            'fees' => $t->fees,
            'total' => $t->total,
        ]);

        $filename = 'investment-transactions-'.now()->format('Y-m-d').'.'.($request->query('format') === 'json' ? 'json' : 'csv');

        return $request->query('format') === 'json'
            ? $this->streamJson($filename, $rows)
            : $this->streamCsv($filename, ['Date', 'Code', 'Investment', 'Type', 'Quantity', 'Unit price', 'Fees', 'Total'], $rows->values());
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'investment_id' => ['required', 'integer', 'exists:investments,id'],
            'transaction_date' => ['required', 'date'],
            'type' => ['required', 'in:buy,sell'],
            'quantity' => ['required', 'numeric', 'min:0'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'fees' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }
}