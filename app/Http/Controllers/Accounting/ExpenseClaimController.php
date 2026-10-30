<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\ExpenseCategory;
use App\Models\ExpenseClaim;
use App\Services\Accounting\LedgerPosting;
use App\Services\NotificationService;
use App\Support\BankLedger;
use App\Support\ExportsCsv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExpenseClaimController extends Controller
{
    use ExportsCsv;

    public function index(Request $request): View
    {
        $claims = ExpenseClaim::query()
            ->with(['reviewer', 'expenseCategory', 'paidFrom'])
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('type'), fn ($q) => $q->where('expense_type', $request->type))
            ->when($request->filled('category'), fn ($q) => $q->where('expense_category_id', $request->category))
            ->when($request->filled('from'), fn ($q) => $q->where('expense_date', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->where('expense_date', '<=', $request->to))
            ->orderByDesc('expense_date')
            ->paginate(20)
            ->withQueryString();

        $pendingTotal = round((float) ExpenseClaim::query()->where('status', 'pending')->get()->sum(fn (ExpenseClaim $claim) => $claim->effectiveAmount()), 2);
        $approvedTotal = round((float) ExpenseClaim::query()->where('status', 'approved')->get()->sum(fn (ExpenseClaim $claim) => $claim->effectiveAmount()), 2);

        $categories = ExpenseCategory::query()->active()->orderBy('name')->get();

        return view('accounting.expense_claims.index', compact('claims', 'pendingTotal', 'approvedTotal', 'categories'));
    }

    public function create(): View
    {
        return view('accounting.expense_claims.create', $this->formData());
    }

    /**
     * Everything the claim forms choose from: the admin-created categories and
     * the accounts a claim can be paid from.
     *
     * @return array<string, mixed>
     */
    private function formData(): array
    {
        return [
            'categories' => ExpenseCategory::query()->active()->orderBy('name')->get(),
            'bankAccounts' => BankAccount::query()->active()->orderBy('name')->get(),
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

        $figures = $this->discountFigures((float) $data['amount'], $data);

        $claim = ExpenseClaim::create([
            'number' => next_document_number('expense_claim', 'EC'),
            'employee_name' => $data['employee_name'],
            'expense_date' => $data['expense_date'],
            /**
             * `expense_type` is the legacy string column the list, the CSV and
             * the older filters still read. It is synced to the chosen
             * category's name rather than left out of step with what was
             * actually selected.
             */
            'expense_type' => $this->typeNameFor((int) $data['expense_category_id']),
            'expense_category_id' => $data['expense_category_id'],
            'bank_account_id' => $data['bank_account_id'] ?? null,
            'merchant' => $data['merchant'] ?? null,
            /**
             * `amount` is the figure the employee was actually billed — the
             * discount off that bill rides along in its own columns — and
             * `effectiveAmount()` is what the approval deducts.
             */
            'amount' => (float) $data['amount'],
            'discount_percent' => $figures['percent'],
            'discount_amount' => $figures['discount'],
            'currency' => $data['currency'],
            'status' => 'pending',
            'notes' => $data['notes'] ?? null,
        ]);

        app(NotificationService::class)->notifyStaff(
            'accounting.expense_claims.view',
            'Expense claim submitted',
            'Claim '.$claim->number.' from '.$claim->employee_name.' for '.money($claim->effectiveAmount(), $claim->currency).' needs approval.',
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
        return view('accounting.expense_claims.edit', array_merge($this->formData(), compact('claim')));
    }

    public function update(Request $request, ExpenseClaim $claim): RedirectResponse
    {
        // Pending keeps the employee correcting their claim before it goes
        // anywhere; rejected is there for the same reason (it returns to a
        // submittable state with nothing posted); approved can be amended
        // because the money it moved is reversible. The two states that stay
        // locked are awaiting-decision and reimbursed.
        if (! in_array($claim->status, ['pending', 'rejected', 'approved'], true)) {
            return back()->with('toasts', [['type' => 'danger', 'message' => 'A claim awaiting approval or already reimbursed cannot be edited.']]);
        }

        $data = $this->validateData($request);

        // An approved claim has already moved money. Editing it unwinds the old
        // figures first — the bank withdrawal and the journal — so the ledger
        // follows the amended document instead of accumulating a posting per
        // edit, and the new figures are re-deducted below. Both leg-ends are
        // keyed to the claim, so this is the same reversal the deletion path
        // uses; a rejected claim never posted anything, so there is nothing to
        // unwind by design.
        $wasPosted = $claim->status === 'approved';

        $figures = $this->discountFigures((float) $data['amount'], $data);

        if ($wasPosted) {
            $claim->loadMissing(['expenseCategory.account', 'paidFrom']);

            DB::transaction(function () use ($claim) {
                BankLedger::reversePayment($claim);
                app(LedgerPosting::class)->reverse($claim, 'Claim amended');
            });
        }

        try {
            $claim->update([
                'employee_name' => $data['employee_name'],
                'expense_date' => $data['expense_date'],
                'expense_type' => $this->typeNameFor((int) $data['expense_category_id']),
                'expense_category_id' => $data['expense_category_id'],
                'bank_account_id' => $data['bank_account_id'] ?? null,
                'merchant' => $data['merchant'] ?? null,
                'amount' => (float) $data['amount'],
                'discount_percent' => $figures['percent'],
                'discount_amount' => $figures['discount'],
                'currency' => $data['currency'],
                'notes' => $data['notes'] ?? null,
            ]);
        } catch (\RuntimeException $e) {
            // The only runtime the update guard can raise is a claim that
            // moved to awaiting-approval between the check above and this
            // write; the postable figures are unchanged either way.
            return back()->with('toasts', [['type' => 'danger', 'message' => $e->getMessage()]]);
        }

        if ($wasPosted) {
            $this->postApprovalLedger($claim);
        }

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
        // `$request->id()` resolves the ROUTE parameter — it named the CLAIM, not
        // the user, so the list used to credit the reviewer with the claim's own id.
        $claim->update(['reviewed_by' => $request->user()?->id, 'reviewed_at' => now()]);

        // Approval is the disbursement: the amount leaves the account the claim
        // names the moment someone accepts the cost. Both legs are idempotent and
        // keyed to the claim, so a retried approval cannot double-charge.
        $this->postApprovalLedger($claim);
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

        $claim->update(['reviewed_by' => $request->user()?->id, 'reviewed_at' => now()]);
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
        // Deleting a decided claim unwinds its money first: the withdrawal it
        // posted and the journal behind it, or the account balance would keep
        // reporting an outflow for a claim that no longer exists. Both calls are
        // no-ops for a claim that never posted anything.
        $claim->loadMissing(['expenseCategory.account', 'paidFrom']);
        BankLedger::reversePayment($claim);
        app(LedgerPosting::class)->reverse($claim, 'Claim deleted');

        $claim->delete();

        return redirect()->route('accounting.expense_claims.index')
            ->with('toasts', [['type' => 'success', 'message' => "Expense claim {$claim->number} deleted."]]);
    }

    public function export(Request $request): StreamedResponse
    {
        $claims = ExpenseClaim::query()
            ->with(['expenseCategory', 'paidFrom'])
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('type'), fn ($q) => $q->where('expense_type', $request->type))
            ->when($request->filled('category'), fn ($q) => $q->where('expense_category_id', $request->category))
            ->when($request->filled('from'), fn ($q) => $q->where('expense_date', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->where('expense_date', '<=', $request->to))
            ->orderByDesc('expense_date')
            ->get();

        return $this->streamCsv('expense-claims-'.now()->format('Y-m-d').'.csv', ['Number', 'Date', 'Employee', 'Category', 'Type', 'Merchant', 'Paid from', 'Amount', 'Status'], $claims->map(fn (ExpenseClaim $c) => [
            $c->number,
            $c->expense_date->format('Y-m-d'),
            $c->employee_name,
            $c->expenseCategory?->name,
            ucfirst($c->expense_type),
            $c->merchant,
            $c->paidFrom?->name,
            $c->amount,
            ucfirst($c->status),
        ]));
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'employee_name' => ['required', 'string', 'max:255'],
            'expense_date' => ['required', 'date'],
            /**
             * A claim names one of the ADMIN-CREATED categories — the old
             * free-text type list is gone. "Active" is part of the rule so a
             * category someone retired cannot be picked on a new claim.
             */
            'expense_category_id' => [
                'required',
                Rule::exists('expense_categories', 'id')->where('is_active', true),
            ],
            /**
             * The account the claim is paid from — a bank account or a
             * petty-cash account. Optional: a claim that leaves it empty is
             * paid outside the tracked accounts and posts no deduction.
             */
            'bank_account_id' => ['nullable', Rule::exists('bank_accounts', 'id')],
            'merchant' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0'],
            /**
             * The discount off the billed figure. Optional, and mutually
             * exclusive in practice with the fixed-amount field below: a claim
             * is discounted by one style or the other, never stacked. The billed
             * figure minus the discount is what the claim actually pays.
             */
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'max:8'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }

    /**
     * Resolve a claim's discount off its billed amount into the figure that is
     * actually paid.
     *
     * A bill of 100 with a 10% discount -- or a specific 10 off the bill the
     * other party gave the employee -- is a claim of 90. The percentage and the
     * fixed amount are two entry styles of one discount and never both apply:
     * a fixed amount wins when one was entered.
     *
     * @param  array<string, mixed>  $data
     * @return array{effective: float, percent: float, discount: float}
     */
    private function discountFigures(float $claimed, array $data): array
    {
        $percent = (float) ($data['discount_percent'] ?? 0);
        $amount = (float) ($data['discount_amount'] ?? 0);

        if ($amount > 0) {
            if ($amount > $claimed) {
                throw ValidationException::withMessages(['amount' => 'Discount cannot exceed the claimed amount.']);
            }

            // A fixed amount off the bill is the discount; the percentage is
            // carried as zero so the two styles never double up.
            return [
                'effective' => round($claimed - $amount, 2),
                'percent' => 0.0,
                'discount' => $amount,
            ];
        }

        $discount = round($claimed * ($percent / 100), 2);

        if ($discount > $claimed) {
            throw ValidationException::withMessages(['amount' => 'Discount cannot exceed the claimed amount.']);
        }

        // A percentage off the bill is the discount; the fixed amount stays zero.
        return [
            'effective' => round($claimed - $discount, 2),
            'percent' => $percent,
            'discount' => 0.0,
        ];
    }

    /**
     * The legacy `expense_type` string, kept in step with the chosen category.
     */
    private function typeNameFor(int $categoryId): string
    {
        return ExpenseCategory::find($categoryId)?->name ?: 'other';
    }

    /**
     * The approval side-effects: the bank withdrawal and the journal.
     *
     * The claim IS the payment — approving it moves the money, and a later
     * "mark as reimbursed" is the administrative stamp that the cash actually
     * got to the employee, never a second posting.
     */
    private function postApprovalLedger(ExpenseClaim $claim): void
    {
        $claim->loadMissing(['expenseCategory.account', 'paidFrom']);

        BankLedger::postExpenseClaim($claim);

        app(LedgerPosting::class)->expenseClaimApproved($claim);
    }
}
