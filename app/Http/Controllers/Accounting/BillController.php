<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Bill;
use App\Models\Supplier;
use App\Support\ExportsCsv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        /**
         * While a request is live the bill is locked, not merely discouraged:
         * the approver must decide on the figures they can see, and the trait's
         * rule applies to any substantive column. Withdraw the request, change
         * the bill, submit again.
         */
        if ($bill->isPendingApproval()) {
            return back()->with('toasts', [['type' => 'danger', 'message' => 'This bill is awaiting approval and cannot be changed. Withdraw the approval request first.']]);
        }

        /**
         * An approved bill is signed off — its amount is what the approver
         * authorised, so remaining changes are refused rather than quietly
         * invalidating the approval that sits behind the payments. The escape
         * hatch is the real workflow: void the bill and record a corrected one
         * (voiding is still allowed for a bill with no payments).
         */
        if ($bill->isApproved()) {
            return back()->with('toasts', [['type' => 'danger', 'message' => 'This bill has been approved and its figures are signed off — it cannot be edited. Void it and record a corrected bill if the figures are wrong.']]);
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

        try {
            DB::transaction(function () use ($data, $bill): void {
                $bill = Bill::query()->lockForUpdate()->findOrFail($bill->id);

                /**
                 * The approval gate. Payment moves real money out, so it is
                 * refused unless someone has approved the bill — the one control
                 * the whole flow exists for. `open` is the recorded-but-not-
                 * approved state; `pending_approval` is mid-review; both must
                 * wait.
                 */
                if (! in_array($bill->status, ['approved', 'partially_paid'], true)) {
                    throw new \RuntimeException(match ($bill->status) {
                        'pending_approval' => 'This bill is awaiting approval and cannot be paid yet.',
                        'open' => 'This bill has not been approved yet. Approval is required before a payment can be recorded.',
                        default => 'This bill is already '.$bill->status.'.',
                    });
                }

                if ((float) $data['amount'] > $bill->balance()) {
                    throw new \RuntimeException('Payment exceeds the outstanding balance of '.money($bill->balance(), $bill->currency).'.');
                }

                $paidAmount = round((float) $bill->paid_amount + (float) $data['amount'], 2);
                $bill->update([
                    'paid_amount' => $paidAmount,
                    'status' => $paidAmount >= (float) $bill->amount ? 'paid' : 'partially_paid',
                ]);
            });
        } catch (\RuntimeException $e) {
            return back()->with('toasts', [['type' => 'danger', 'message' => $e->getMessage()]]);
        }

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

        /**
         * Voiding while a request is live must retire that request too. An
         * approval engine that accepts a decision against a cancelled bill
         * would stamp some other approver's decision onto a document that no
         * longer exists — and worse, approve() would resurrect the bill to
         * `approved` past its voiding.
         */
        $bill->liveApprovalFor()?->update([
            'status' => 'cancelled',
            'decided_by' => $request->user()?->id,
            'decided_at' => now(),
        ]);

        $bill->update(['status' => 'void']);

        return back()->with('toasts', [['type' => 'success', 'message' => "Bill {$bill->number} voided."]]);
    }

    /**
     * Hand the bill to the approver. The bill's own maker-checker rules apply
     * from the trait: it must be in a submittable status and must not already
     * carry a live request.
     */
    public function submit(Request $request, Bill $bill): RedirectResponse
    {
        try {
            $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);

            $bill->submitForApproval($request->user(), 1, $request->input('comment'));
        } catch (\RuntimeException $e) {
            return back()->with('toasts', [['type' => 'danger', 'message' => $e->getMessage()]]);
        }

        return back()->with('toasts', [[
            'type' => 'success',
            'message' => "Bill {$bill->number} submitted for approval. It cannot be paid until it is approved.",
        ]]);
    }

    /**
     * Grant approval, which is what makes the bill payable.
     *
     * Bills post no journals of their own — the payment records money moving —
     * so approval changes nothing in the ledger; it only opens the payment gate.
     */
    public function approve(Request $request, Bill $bill): RedirectResponse
    {
        try {
            $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);

            $bill->approve($request->user(), $request->input('comment'));
        } catch (\RuntimeException $e) {
            return back()->with('toasts', [['type' => 'danger', 'message' => $e->getMessage()]]);
        }

        return back()->with('toasts', [[
            'type' => 'success',
            'message' => "Bill {$bill->number} approved. Payments can now be recorded against it.",
        ]]);
    }

    /**
     * Refuse approval, recording why. Back to `open` so the owner can correct
     * and resubmit; the refusal stays in the trail the next request carries.
     */
    public function reject(Request $request, Bill $bill): RedirectResponse
    {
        try {
            $data = $request->validate([
                'comment' => ['required', 'string', 'max:2000'],
            ]);

            $bill->reject($request->user(), $data['comment']);
        } catch (\RuntimeException $e) {
            return back()->with('toasts', [['type' => 'danger', 'message' => $e->getMessage()]]);
        }

        return back()->with('toasts', [[
            'type' => 'success',
            'message' => "Bill {$bill->number} rejected. It can be corrected and resubmitted.",
        ]]);
    }

    /**
     * Pull a live request back. Only the requester may do this — that rule
     * lives in the trait and is enforced even for an Admin who is not the
     * requester.
     */
    public function withdraw(Request $request, Bill $bill): RedirectResponse
    {
        try {
            $bill->withdrawApproval($request->user());
        } catch (\RuntimeException $e) {
            return back()->with('toasts', [['type' => 'danger', 'message' => $e->getMessage()]]);
        }

        return back()->with('toasts', [[
            'type' => 'success',
            'message' => "Approval request for {$bill->number} withdrawn.",
        ]]);
    }

    public function destroy(Bill $bill): RedirectResponse
    {
        /**
         * Deleting a bill whose request is live would orphan the request: soft
         * deletes keep the bill row and the approver's inbox would list a bill
         * that is no longer really there. Withdraw first — or void it instead
         * if the disagreement is with the bill itself.
         */
        if ($bill->isPendingApproval()) {
            return back()->with('toasts', [['type' => 'danger', 'message' => 'This bill is awaiting approval. Withdraw the request before deleting it.']]);
        }

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
