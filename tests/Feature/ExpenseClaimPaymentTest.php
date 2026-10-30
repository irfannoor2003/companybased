<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\ExpenseCategory;
use App\Models\ExpenseClaim;
use App\Models\JournalEntry;
use App\Models\JournalEntryItem;
use App\Models\User;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * The expense claim as a PAYMENT, not a status machine.
 *
 * Approving a claim is the moment its cost becomes real: the amount leaves the
 * named bank or cash account and posts as a journal. These tests hold the whole
 * money path — who may approve, what the account loses, what the ledger records,
 * and how each of those unwinds when the claim is deleted.
 */
class ExpenseClaimPaymentTest extends TestCase
{
    use SeedsDatabase;

    private ?User $admin = null;

    private function admin(): User
    {
        return $this->admin ??= $this->userForRole('Admin');
    }

    private function accountant(array $attributes = []): User
    {
        return $this->userForRole('Accountant', $attributes);
    }

    private function category(array $attributes = []): ExpenseCategory
    {
        $suffix = substr(md5(uniqid('', true)), 0, 8);

        return ExpenseCategory::create(array_merge([
            'name' => 'Staff Travel '.$suffix,
            'slug' => 'staff-travel-'.$suffix,
            'is_active' => true,
        ], $attributes));
    }

    private function bankAccount(array $attributes = []): BankAccount
    {
        $suffix = substr(md5(uniqid('', true)), 0, 6);

        return BankAccount::create(array_merge([
            'name' => 'Petty Cash '.$suffix,
            'account_type' => 'cash',
            'currency' => 'USD',
            'opening_balance' => '1000.00',
            'is_active' => true,
        ], $attributes));
    }

    private function payload(?BankAccount $from, ExpenseCategory $category, array $overrides = []): array
    {
        return array_merge([
            'employee_name' => 'Ahmed Saleem',
            'expense_date' => now()->toDateString(),
            'expense_category_id' => $category->id,
            'bank_account_id' => $from?->id,
            'merchant' => 'PakSounds Fares',
            'amount' => '100.00',
            'currency' => 'USD',
            'notes' => null,
        ], $overrides);
    }

    // A claim recorded by someone (POST + submit + approve), for tests that
    // exercise the money rather than the form. `$overrides['_from']` left
    // unset means the claim names NO account — the out-of-tracked-accounts
    // state. `$decider` is who grants the final approval; the maker decides
    // themselves when it is unset and they may.
    private function approvedClaim(User $maker, array $overrides = [], ?User $decider = null): ExpenseClaim
    {
        $category = $overrides['_category'] ?? $this->category();
        $from = $overrides['_from'] ?? null;
        unset($overrides['_category'], $overrides['_from']);

        $this->actingAs($maker)
            ->post(route('accounting.expense_claims.store'), $this->payload($from, $category, $overrides))
            ->assertRedirect();

        $claim = ExpenseClaim::query()->where('employee_name', 'Ahmed Saleem')->latest('id')->firstOrFail();

        $this->actingAs($maker)->post(route('accounting.expense_claims.submit', $claim))->assertRedirect();

        $this->actingAs($decider ?? $maker)
            ->post(route('accounting.expense_claims.approve', $claim))
            ->assertRedirect();

        return $claim->fresh(['expenseCategory', 'paidFrom']);
    }

    // ── Recording ─────────────────────────────────────────────────────────────

    public function test_a_claim_remembers_its_category_account_and_legacy_type(): void
    {
        $category = $this->category();
        $from = $this->bankAccount();

        $this->actingAs($this->admin())
            ->post(route('accounting.expense_claims.store'), $this->payload($from, $category))
            ->assertRedirect();

        $claim = ExpenseClaim::query()->where('employee_name', 'Ahmed Saleem')->latest('id')->firstOrFail();

        $this->assertSame($category->id, $claim->expense_category_id);
        $this->assertSame($from->id, $claim->bank_account_id);

        // The legacy free-text column stays in step with the chosen category:
        // the CSV and the old filters still read it, and it must not disagree
        // with what was actually selected.
        $this->assertSame($category->name, $claim->expense_type);
        $this->assertSame('pending', $claim->status);
    }

    public function test_an_orphaned_account_on_a_paid_from_field_is_rejected(): void
    {
        $category = $this->category();

        $this->actingAs($this->admin())
            ->post(route('accounting.expense_claims.store'), $this->payload($this->bankAccount(), $category, [
                'bank_account_id' => 999999,
            ]))
            ->assertInvalid('bank_account_id');

        $this->assertSame(0, ExpenseClaim::where('employee_name', 'Ahmed Saleem')->count());
    }

    // ── The permission to decide ─────────────────────────────────────────────

    public function test_an_admin_can_approve_a_claim_they_raised_themselves(): void
    {
        $admin = $this->admin();
        $this->assertTrue($admin->can('accounting.expense_claims.self_approve'));

        $claim = $this->approvedClaim($admin);

        $this->assertSame('approved', $claim->status);

        // The trail stays honest about a self-decision: requester and decider
        // are recorded as the same person rather than hidden behind one name.
        $request = $claim->approvalHistory()->first();
        $this->assertSame('approved', $request->status);
        $this->assertSame($admin->id, $request->requested_by);
        $this->assertSame($admin->id, $request->decided_by);
    }

    public function test_an_accountant_always_waits_for_the_admin_on_their_own_claim(): void
    {
        $accountant = $this->accountant();
        $this->assertTrue($accountant->can('accounting.expense_claims.approve'));
        $this->assertFalse($accountant->can('accounting.expense_claims.self_approve'));

        $category = $this->category();
        $from = $this->bankAccount();

        $this->actingAs($accountant)
            ->post(route('accounting.expense_claims.store'), $this->payload($from, $category))
            ->assertRedirect();
        $claim = ExpenseClaim::query()->where('employee_name', 'Ahmed Saleem')->latest('id')->firstOrFail();
        $this->actingAs($accountant)->post(route('accounting.expense_claims.submit', $claim))->assertRedirect();

        // Maker insisting on approving their own request is refused ON THE
        // MODEL, not just hidden in the UI — the POST comes back with the
        // refusal message and nothing has moved.
        $response = $this->actingAs($accountant)
            ->post(route('accounting.expense_claims.approve', $claim))
            ->assertRedirect();

        $messages = collect($response->getSession()->get('toasts', []))->pluck('message')->join(' ');
        $this->assertStringContainsString('raised yourself', $messages);

        // The refusal moved nothing. The claim is in the trait's awaiting state
        // and the request row is untouched.
        $this->assertSame('pending_approval', $claim->fresh()->status);

        // The separation holds even against a crafted request: the request
        // itself is still awaiting a DIFFERENT person.
        $this->assertSame('pending', $claim->liveApproval()->status);

        // The Admin breaks the deadlock.
        $this->actingAs($this->admin())->post(route('accounting.expense_claims.approve', $claim))->assertRedirect();
        $this->assertSame('approved', $claim->fresh()->status);
    }

    // ── The money: approval is the disbursement ───────────────────────────────

    public function test_approval_deducts_the_amount_from_the_named_account(): void
    {
        $from = $this->bankAccount(['opening_balance' => '1000.00']);

        $claim = $this->approvedClaim($this->admin(), ['_from' => $from]);

        $this->assertSame('approved', $claim->status);
        $this->assertSame(900.0, $from->fresh()->balance());

        $leg = BankTransaction::query()
            ->where('reference_type', 'App\Models\ExpenseClaim')
            ->where('reference_id', $claim->id)
            ->first();

        $this->assertNotNull($leg, 'the approval must post a bank transaction');
        $this->assertSame('withdrawal', $leg->type);
        $this->assertSame('100.00', $leg->amount);
        $this->assertSame($from->id, $leg->bank_account_id);
    }

    public function test_a_percentage_discount_on_a_claim_pays_out_the_discounted_amount(): void
    {
        $from = $this->bankAccount(['opening_balance' => '1000.00']);

        // A bill of 100 with a 10% discount off the other party gave the
        // employee is a claim of 90 -- and the approval must deduct 90.
        $claim = $this->approvedClaim($this->admin(), ['_from' => $from, 'amount' => '100.00', 'discount_percent' => '10']);

        $this->assertSame('approved', $claim->status);
        $this->assertSame('100.00', (string) $claim->amount);
        $this->assertSame('10.00', (string) $claim->discount_percent);
        $this->assertSame('0.00', (string) $claim->discount_amount);
        $this->assertSame(90.0, $claim->effectiveAmount());

        $this->assertSame(910.0, $from->fresh()->balance());

        $leg = BankTransaction::query()
            ->where('reference_type', 'App\Models\ExpenseClaim')
            ->where('reference_id', $claim->id)
            ->first();

        $this->assertNotNull($leg, 'the approval must post a bank transaction');
        $this->assertSame('withdrawal', $leg->type);
        $this->assertSame('90.00', $leg->amount);
        $this->assertSame($from->id, $leg->bank_account_id);

        // The journal charges the discounted cost on both sides of the entry.
        $entry = JournalEntry::query()
            ->where('reference', 'App\Models\ExpenseClaim:'.$claim->id.':approved')
            ->firstOrFail();

        $this->assertDatabaseHas('journal_entry_items', [
            'journal_entry_id' => $entry->id,
            'account_id' => $this->account('6000', 'expense', 'Operating Expenses')->id,
            'debit' => '90.00',
        ]);
    }

    public function test_a_fixed_amount_discount_on_a_claim_pays_out_the_discounted_amount(): void
    {
        $from = $this->bankAccount(['opening_balance' => '1000.00']);

        // A bill of 100 with a specific Rs 25 off the other party is a claim of
        // 75 -- the fixed amount is the discount, and it is the one applied.
        $claim = $this->approvedClaim($this->admin(), ['_from' => $from, 'amount' => '100.00', 'discount_amount' => '25']);

        $this->assertSame('approved', $claim->status);
        $this->assertSame('100.00', (string) $claim->amount);
        $this->assertSame('0.00', (string) $claim->discount_percent);
        $this->assertSame('25.00', (string) $claim->discount_amount);
        $this->assertSame(75.0, $claim->effectiveAmount());

        $this->assertSame(925.0, $from->fresh()->balance());

        $leg = BankTransaction::query()
            ->where('reference_type', 'App\Models\ExpenseClaim')
            ->where('reference_id', $claim->id)
            ->first();

        $this->assertNotNull($leg, 'the approval must post a bank transaction');
        $this->assertSame('75.00', $leg->amount);
        $this->assertSame($from->id, $leg->bank_account_id);
    }

    public function test_a_discount_larger_than_the_claimed_amount_is_refused(): void
    {
        $from = $this->bankAccount();

        $this->actingAs($this->admin())
            ->post(route('accounting.expense_claims.store'), $this->payload($from, $this->category(), [
                'amount' => '100.00',
                'discount_amount' => '150',
            ]))
            ->assertSessionHasErrors('amount');

        $this->assertSame(0, ExpenseClaim::where('employee_name', 'Ahmed Saleem')->count());
    }

    public function test_a_claim_with_no_named_account_approves_without_a_deduction(): void
    {
        // No `_from` in the overrides: the claim names no account at all —
        // the "paid outside the tracked accounts" state.
        $claim = $this->approvedClaim($this->admin());

        $this->assertSame('approved', $claim->fresh()->status);
        $this->assertNull($claim->bank_account_id);
        $this->assertSame(
            0,
            BankTransaction::where('reference_type', 'App\Models\ExpenseClaim')->where('reference_id', $claim->id)->count(),
        );
    }

    public function test_the_approval_posts_a_balanced_journal_of_the_cost_and_the_payer(): void
    {
        // The category's mapped expense account is the debit; a petty-cash
        // account falls back to Cash on Hand as the credit.
        $expenseAccount = $this->account('6100', 'expense', 'Rent and Utilities');
        $crypto = $this->category(['account_id' => $expenseAccount->id]);
        $from = $this->bankAccount(['account_type' => 'cash']);

        $claim = $this->approvedClaim($this->admin(), ['_category' => $crypto, '_from' => $from]);

        $entry = JournalEntry::query()
            ->where('reference', 'App\Models\ExpenseClaim:'.$claim->id.':approved')
            ->first();

        $this->assertNotNull($entry, 'the approval must post a journal for the cost');
        $this->assertSame('posted', $entry->status);

        $this->assertDatabaseHas('journal_entry_items', [
            'journal_entry_id' => $entry->id,
            'account_id' => $expenseAccount->id,
            'debit' => '100.00',
            'credit' => '0.00',
        ]);

        $cashCode = Account::where('code', '1000')->firstOrFail();

        $this->assertDatabaseHas('journal_entry_items', [
            'journal_entry_id' => $entry->id,
            'account_id' => $cashCode->id,
            'debit' => '0.00',
            'credit' => '100.00',
        ]);

        // Balanced — the posting engine refuses anything else, but the test
        // holds it anyway because a lopsided journal here would reconcile to
        // nothing downstream.
        $this->assertSame(
            (float) JournalEntryItem::where('journal_entry_id', $entry->id)->sum('debit'),
            (float) JournalEntryItem::where('journal_entry_id', $entry->id)->sum('credit'),
        );
    }

    public function test_an_unmapped_category_charges_the_operating_expense_fallback(): void
    {
        // No account on the category: the cost must still reach the P&L — the
        // deployment's Operating Expenses (6000) account, not nowhere. The
        // claim is still PAID FROM an account, which is what gives the journal
        // its credit side.
        $fallback = $this->account('6000', 'expense', 'Operating Expenses');
        $category = $this->category(['account_id' => null]);

        $claim = $this->approvedClaim($this->admin(), [
            '_category' => $category,
            '_from' => $this->bankAccount(),
        ]);

        $entry = JournalEntry::query()
            ->where('reference', 'App\Models\ExpenseClaim:'.$claim->id.':approved')
            ->firstOrFail();

        $this->assertDatabaseHas('journal_entry_items', [
            'journal_entry_id' => $entry->id,
            'account_id' => $fallback->id,
            'debit' => '100.00',
        ]);
    }

    // ── Reversal ─────────────────────────────────────────────────────────────

    public function test_deleting_an_approved_claim_restores_the_account_and_voids_the_journal(): void
    {
        $from = $this->bankAccount(['opening_balance' => '1000.00']);

        $claim = $this->approvedClaim($this->admin(), ['_from' => $from]);
        $this->assertSame(900.0, $from->fresh()->balance());

        $this->actingAs($this->admin())
            ->delete(route('accounting.expense_claims.destroy', $claim))
            ->assertRedirect();

        // The claim is soft-deleted, but its MONEY must be gone: the ledger can
        // never keep reporting an outflow for a claim that no longer exists.
        $this->assertSoftDeleted($claim);

        // The bank leg is REVERSED — soft-deleted, not left counting. The raw
        // table still carries the row (assertDatabaseMissing would see it even
        // trashed), so the assertion is on its being reimbursed-that-never-was:
        // displaced from the balance rather than destroyed.
        $leg = BankTransaction::query()
            ->withTrashed()
            ->where('reference_type', 'App\Models\ExpenseClaim')
            ->where('reference_id', $claim->id)
            ->first();
        $this->assertNotNull($leg, 'the bank leg row survives for the reconciliation audit trail');
        $this->assertNotNull($leg->deleted_at, 'the bank leg must be reversed, not left counting against the balance');

        $entry = JournalEntry::query()
            ->where('reference', 'App\Models\ExpenseClaim:'.$claim->id.':approved')
            ->first();
        $this->assertNotNull($entry, 'the journal row survives for the audit trail');
        $this->assertSame('void', $entry->status);

        $this->assertSame(1000.0, $from->fresh()->balance());
    }

    // ── Reimburse never doubles the deduction ────────────────────────────────

    public function test_marking_a_claim_reimbursed_never_deducts_twice(): void
    {
        $from = $this->bankAccount(['opening_balance' => '1000.00']);

        $claim = $this->approvedClaim($this->admin(), ['_from' => $from]);
        $this->assertSame(900.0, $from->fresh()->balance());

        $this->actingAs($this->admin())
            ->post(route('accounting.expense_claims.reimburse', $claim))
            ->assertRedirect();

        $this->assertSame('reimbursed', $claim->fresh()->status);
        $this->assertSame(900.0, $from->fresh()->balance(), 'reimbursement is paperwork, not a second payment');
    }

    // ── Editing an approved claim reverses and re-deducts ────────────────────

    public function test_editing_an_approved_claim_replaces_the_deduction_with_the_new_amount(): void
    {
        $from = $this->bankAccount(['opening_balance' => '1000.00']);
        $category = $this->category();

        $claim = $this->approvedClaim($this->admin(), ['_category' => $category, '_from' => $from]);
        $this->assertSame(900.0, $from->fresh()->balance());

        $this->actingAs($this->admin())
            ->put(route('accounting.expense_claims.update', $claim), $this->payload($from, $category, [
                'amount' => '250.00',
            ]))
            ->assertRedirect();

        $claim = $claim->fresh(['expenseCategory', 'paidFrom']);
        $this->assertSame('250.00', (string) $claim->amount);

        // The old deduction is gone and exactly one withdrawal counts — the
        // amended one. A second posting per edit would accumulate silently.
        $legs = BankTransaction::query()
            ->where('reference_type', 'App\Models\ExpenseClaim')
            ->where('reference_id', $claim->id)
            ->get();

        $this->assertCount(1, $legs, 'the edit must not leave the old withdrawal counting');
        $this->assertSame('250.00', $legs->first()->amount);
        $this->assertSame(750.0, $from->fresh()->balance());

        // The journal follows the document: the amended figures post, the
        // original stays void for the audit trail.
        $entries = JournalEntry::query()
            ->where('reference', 'App\Models\ExpenseClaim:'.$claim->id.':approved')
            ->get();
        $this->assertCount(2, $entries);
        $this->assertSame(['posted', 'void'], $entries->pluck('status')->sort()->values()->all());
    }

    public function test_editing_an_approved_claim_moves_the_deduction_to_the_new_account(): void
    {
        $old = $this->bankAccount(['opening_balance' => '1000.00']);
        $new = $this->bankAccount(['opening_balance' => '500.00']);
        $category = $this->category();

        $claim = $this->approvedClaim($this->admin(), ['_category' => $category, '_from' => $old]);
        $this->assertSame(900.0, $old->fresh()->balance());

        $this->actingAs($this->admin())
            ->put(route('accounting.expense_claims.update', $claim), $this->payload($new, $category))
            ->assertRedirect();

        $claim = $claim->fresh(['expenseCategory', 'paidFrom']);

        $this->assertSame($new->id, $claim->bank_account_id);
        $this->assertSame(1000.0, $old->fresh()->balance(), 'the old account must be repaid on edit');
        $this->assertSame(400.0, $new->fresh()->balance(), 'the new account must carry the deduction');

        $leg = BankTransaction::query()
            ->where('reference_type', 'App\Models\ExpenseClaim')
            ->where('reference_id', $claim->id)
            ->firstOrFail();

        $this->assertSame($new->id, $leg->bank_account_id);
    }

    public function test_a_reimbursed_claim_cannot_be_edited(): void
    {
        $from = $this->bankAccount(['opening_balance' => '1000.00']);
        $category = $this->category();

        $claim = $this->approvedClaim($this->admin(), ['_category' => $category, '_from' => $from, 'merchant' => 'Original']);
        $this->actingAs($this->admin())
            ->post(route('accounting.expense_claims.reimburse', $claim))
            ->assertRedirect();

        $response = $this->actingAs($this->admin())
            ->put(route('accounting.expense_claims.update', $claim), $this->payload($from, $category, [
                'merchant' => 'Amended',
            ]))
            ->assertRedirect();

        $messages = collect($response->getSession()->get('toasts', []))->pluck('message')->join(' ');
        $this->assertStringContainsString('cannot be edited', $messages);

        // Nothing changed and the deduction is untouched — a reimbursed claim
        // is paid-out paperwork, not a document still in play.
        $this->assertSame('Original', $claim->fresh()->merchant);
        $this->assertSame(900.0, $from->fresh()->balance());
    }

    // ── Reports ─────────────────────────────────────────────────────────────

    public function test_approved_claims_reach_the_grand_report_within_the_period(): void
    {
        $claim = $this->approvedClaim($this->admin(), ['amount' => '137.25']);

        $response = $this->actingAs($this->admin())
            ->get(route('reports.grand'))
            ->assertOk();

        // The report carries the approved-and-paid costs of the period.
        $this->assertStringContainsString('137.25', $response->getContent());

        // A claim still in discussion is NOT yet a cost — and this one is sized
        // so no other figure on the page can collide with it.
        $category = $this->category();
        $this->actingAs($this->admin())
            ->post(route('accounting.expense_claims.store'), $this->payload($this->bankAccount(), $category, ['amount' => '54321.50']))
            ->assertRedirect();

        $response = $this->actingAs($this->admin())->get(route('reports.grand'))->assertOk();
        $this->assertStringNotContainsString('54321.50', $response->getContent());
    }
}
