<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Bill;
use App\Models\Supplier;
use App\Support\ExportsCsv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BillController extends Controller
{
    use ExportsCsv;

    public function index(Request $request): View
    {
        $bills = Bill::query()
            ->with('supplier')
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('from'), fn ($q) => $q->where('bill_date', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->where('bill_date', '<=', $request->to))
            ->orderByDesc('bill_date')
            ->paginate(20)
            ->withQueryString();

        $totalDue = round((float) Bill::query()->whereIn('status', ['open', 'partially_paid'])->get()->sum(fn (Bill $b) => $b->balance()), 2);
        $totalPaid = round((float) Bill::query()->sum('paid_amount'), 2);

        return view('accounting.bills.index', compact('bills', 'totalDue', 'totalPaid'));
    }

    public function create(): View
    {
        return view('accounting.bills.create', [
            'accounts' => Account::query()->active()->orderBy('code')->get(),
            'suppliers' => Supplier::query()->orderBy('company_name')->get(['id', 'company_name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'vendor_name' => ['required', 'string', 'max:255'],
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')],
            'bill_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:bill_date'],
            'reference' => ['nullable', 'string', 'max:120'],
            'currency' => ['required', 'string', 'max:8'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.account_id' => ['required', 'integer'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.amount' => ['required', 'numeric', 'gt:0'],
        ]);

        $amount = round(array_sum(array_column($data['lines'], 'amount')), 2);

        $bill = Bill::create([
            'number' => next_document_number('bill', 'AP'),
            'vendor_name' => $data['vendor_name'],
            'supplier_id' => $data['supplier_id'] ?? null,
            'bill_date' => $data['bill_date'],
            'due_date' => $data['due_date'] ?? null,
            'amount' => (string) $amount,
            'paid_amount' => '0.00',
            'currency' => $data['currency'],
            'status' => 'open',
            'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        foreach ($data['lines'] as $line) {
            $bill->items()->create([
                'account_id' => $line['account_id'],
                'description' => $line['description'] ?? null,
                'amount' => (string) $line['amount'],
            ]);
        }

        return redirect()->route('accounting.bills.show', $bill)
            ->with('toasts', [['type' => 'success', 'message' => "Bill {$bill->number} created."]]);
    }

    public function show(Bill $bill): View
    {
        $bill->load(['items.account', 'supplier']);

        return view('accounting.bills.show', compact('bill'));
    }

    public function edit(Bill $bill): View
    {
        $bill->load('items');

        return view('accounting.bills.edit', [
            'bill' => $bill,
            'accounts' => Account::query()->active()->orderBy('code')->get(),
            'suppliers' => Supplier::query()->orderBy('company_name')->get(['id', 'company_name']),
        ]);
    }

    public function update(Request $request, Bill $bill): RedirectResponse
    {
        if (in_array($bill->status, ['paid', 'void'], true)) {
            return back()->with('toasts', [['type' => 'danger', 'message' => 'Paid or void bills cannot be edited.']]);
        }

        $data = $request->validate([
            'vendor_name' => ['required', 'string', 'max:255'],
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')],
            'bill_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:bill_date'],
            'reference' => ['nullable', 'string', 'max:120'],
            'currency' => ['required', 'string', 'max:8'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.account_id' => ['required', 'integer'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.amount' => ['required', 'numeric', 'gt:0'],
        ]);

        $amount = round(array_sum(array_column($data['lines'], 'amount')), 2);

        $bill->update([
            'vendor_name' => $data['vendor_name'],
            'supplier_id' => $data['supplier_id'] ?? null,
            'bill_date' => $data['bill_date'],
            'due_date' => $data['due_date'] ?? null,
            'amount' => (string) $amount,
            'currency' => $data['currency'],
            'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        $bill->items()->delete();
        foreach ($data['lines'] as $line) {
            $bill->items()->create([
                'account_id' => $line['account_id'],
                'description' => $line['description'] ?? null,
                'amount' => (string) $line['amount'],
            ]);
        }

        $this->recalculateStatus($bill);

        return back()->with('toasts', [['type' => 'success', 'message' => "Bill {$bill->number} updated."]]);
    }

    public function recordPayment(Request $request, Bill $bill): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'paid_at' => ['required', 'date'],
        ]);

        if (in_array($bill->status, ['paid', 'void'], true)) {
            return back()->with('toasts', [['type' => 'danger', 'message' => 'This bill is already '.$bill->status.'.']]);
        }

        $remaining = $bill->balance();

        if ((float) $data['amount'] > $remaining) {
            return back()->with('toasts', [['type' => 'danger', 'message' => 'Payment exceeds the outstanding balance of '.money($remaining, $bill->currency).'.']]);
        }

        $bill->update([
            'paid_amount' => round((float) $bill->paid_amount + (float) $data['amount'], 2),
            'status' => $bill->isPaid() ? 'paid' : 'partially_paid',
        ]);

        return back()->with('toasts', [['type' => 'success', 'message' => 'Payment of '.money($data['amount'], $bill->currency).' recorded on '.$bill->number.'.']]);
    }

    public function updateStatus(Request $request, Bill $bill): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['void'])],
        ]);

        if (in_array($bill->status, ['paid', 'void'], true)) {
            return back()->with('toasts', [['type' => 'danger', 'message' => 'This bill cannot be voided.']]);
        }

        $bill->update(['status' => 'void']);

        return back()->with('toasts', [['type' => 'success', 'message' => "Bill {$bill->number} voided."]]);
    }

    public function destroy(Bill $bill): RedirectResponse
    {
        if ($bill->paid_amount > 0) {
            return back()->with('toasts', [['type' => 'danger', 'message' => 'This bill has payments recorded and cannot be deleted.']]);
        }

        $bill->delete();

        return redirect()->route('accounting.bills.index')
            ->with('toasts', [['type' => 'success', 'message' => "Bill {$bill->number} deleted."]]);
    }

    public function export(Request $request): StreamedResponse
    {
        $bills = Bill::query()
            ->with('supplier')
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('from'), fn ($q) => $q->where('bill_date', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->where('bill_date', '<=', $request->to))
            ->orderByDesc('bill_date')
            ->get();

        return $this->streamCsv('bills-'.now()->format('Y-m-d').'.csv', ['Number', 'Date', 'Due', 'Vendor', 'Amount', 'Paid', 'Balance', 'Status'], $bills->map(fn (Bill $b) => [
            $b->number,
            $b->bill_date->format('Y-m-d'),
            $b->due_date?->format('Y-m-d'),
            $b->vendor_name,
            $b->amount,
            $b->paid_amount,
            $b->balance(),
            ucfirst($b->status),
        ]));
    }

    private function recalculateStatus(Bill $bill): void
    {
        if ($bill->isPaid()) {
            $bill->update(['status' => 'paid']);
        } elseif ($bill->paid_amount > 0) {
            $bill->update(['status' => 'partially_paid']);
        } else {
            $bill->update(['status' => 'open']);
        }
    }
}