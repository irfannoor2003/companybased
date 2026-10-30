<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Bill;
use App\Models\User;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * The bill approval gate.
 *
 * A vendor's recorded bill is not yet payable money — an approval turns the
 * record into an authorisation, and only then does payment move. These tests
 * pin the whole rule the business asked for: nothing is paid before somebody
 * decides; the Admin may decide their OWN bills (self-approval); an
 * Accountant's own bills must wait for somebody else.
 */
class BillApprovalTest extends TestCase
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

    /**
     * A bill stored through the form by `$maker`, still open.
     */
    private function storedBill(User $maker, array $overrides = []): Bill
    {
        $vendor = $overrides['vendor_name'] ?? 'Vendor '.substr(md5(uniqid('', true)), 0, 6);

        $payload = array_merge([
            'vendor_name' => $vendor,
            'supplier_id' => null,
            'bill_date' => now()->toDateString(),
            'due_date' => null,
            'reference' => null,
            'currency' => 'USD',
            'notes' => null,
            'lines' => [
                [
                    'account_id' => Account::where('code', '6000')->firstOrFail()->id,
                    // 'description' => 'Quarterly filing fee', — nullable
                    'amount' => '100.00',
                ],
            ],
        ], $overrides);

        $this->actingAs($maker)->post(route('accounting.bills.store'), $payload)->assertRedirect();

        return Bill::query()->where('vendor_name', $vendor)->firstOrFail();
    }

    private function pay(User $user, Bill $bill, string $amount): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user)->post(route('accounting.bills.payment', $bill), [
            'amount' => $amount,
            'paid_at' => now()->toDateString(),
        ]);
    }

    // ── The gate on payment ─────────────────────────────────────────────────

    public function test_a_bill_cannot_be_paid_before_approval(): void
    {
        $bill = $this->storedBill($this->admin());

        $this->pay($this->admin(), $bill, '100.00')->assertRedirect();

        $this->assertSame('open', $bill->fresh()->status);
        $this->assertSame('0.00', $bill->fresh()->paid_amount);
    }

    public function test_an_awaiting_approval_bill_cannot_be_paid(): void
    {
        $bill = $this->storedBill($this->admin());

        $this->actingAs($this->admin())->post(route('accounting.bills.submit', $bill))->assertRedirect();
        $this->assertSame('pending_approval', $bill->fresh()->status);

        $this->pay($this->admin(), $bill, '50.00')->assertRedirect();

        $this->assertSame('pending_approval', $bill->fresh()->status);
        $this->assertSame('0.00', $bill->fresh()->paid_amount);
    }

    // ── Who decides: the maker-checker rule ─────────────────────────────────

    public function test_the_admin_can_approve_and_pay_a_bill_they_raised_themselves(): void
    {
        $admin = $this->admin();
        $this->assertTrue($admin->can('accounting.bills.self_approve'));

        $bill = $this->storedBill($admin);

        $this->actingAs($admin)->post(route('accounting.bills.submit', $bill))->assertRedirect();
        $this->actingAs($admin)->post(route('accounting.bills.approve', $bill))->assertRedirect();

        $this->assertSame('approved', $bill->fresh()->status);

        $request = $bill->approvalHistory()->first();
        $this->assertSame('approved', $request->status);
        $this->assertSame($admin->id, $request->requested_by, 'the trail names the requester');
        $this->assertSame($admin->id, $request->decided_by, 'and the trail names the self-decision outright');
    }

    public function test_an_accountant_always_waits_for_the_admin_on_their_own_bill(): void
    {
        $accountant = $this->accountant();
        $this->assertTrue($accountant->can('accounting.bills.approve'), 'the accountant decides OTHERS\' bills');
        $this->assertFalse($accountant->can('accounting.bills.self_approve'), 'never their own');

        $bill = $this->storedBill($accountant);

        $this->actingAs($accountant)->post(route('accounting.bills.submit', $bill))->assertRedirect();

        // The maker's attempt to decide their own request is refused on the
        // model and reported, not swallowed or silently approved.
        $response = $this->actingAs($accountant)
            ->post(route('accounting.bills.approve', $bill))
            ->assertRedirect();

        $messages = collect($response->getSession()->get('toasts', []))->pluck('message')->join(' ');
        $this->assertStringContainsString('raised yourself', $messages);
        $this->assertSame('pending_approval', $bill->fresh()->status);
        $this->assertSame('pending', $bill->liveApproval()->status);

        // Only the Admin breaks the deadlock — and once approved, the maker
        // can carry out the payment they requested.
        $this->actingAs($this->admin())->post(route('accounting.bills.approve', $bill))->assertRedirect();
        $this->assertSame('approved', $bill->fresh()->status);

        $this->pay($accountant, $bill, '100.00')->assertRedirect();
        $this->assertSame('paid', $bill->fresh()->status);
        $this->assertSame('100.00', $bill->fresh()->paid_amount);
    }

    public function test_an_accountant_can_approve_a_bill_they_did_not_raise(): void
    {
        $bill = $this->storedBill($this->admin());

        $this->actingAs($this->admin())->post(route('accounting.bills.submit', $bill))->assertRedirect();

        $this->actingAs($this->accountant())
            ->post(route('accounting.bills.approve', $bill))
            ->assertRedirect();

        $this->assertSame('approved', $bill->fresh()->status);
        $this->assertNotSame(
            $this->admin()->id,
            $bill->approvalHistory()->first()->decided_by,
            'maker-checker means the decision came from the other office',
        );
    }

    // ── Locking and unwinding ───────────────────────────────────────────────

    public function test_a_bill_awaiting_approval_is_locked_to_edits(): void
    {
        $bill = $this->storedBill($this->admin());

        $this->actingAs($this->admin())->post(route('accounting.bills.submit', $bill))->assertRedirect();

        // Editing underneath the approver — the approver must decide the
        // numbers they saw.
        $this->actingAs($this->admin())->put(route('accounting.bills.update', $bill), [
            'vendor_name' => 'Tampered Vendor',
            'supplier_id' => null,
            'bill_date' => now()->toDateString(),
            'currency' => 'USD',
            'lines' => [
                [
                    'account_id' => Account::where('code', '6000')->firstOrFail()->id,
                    'amount' => '999.00',
                ],
            ],
        ])->assertRedirect();

        $this->assertSame('pending_approval', $bill->fresh()->status);
        $this->assertSame('100.00', $bill->fresh()->amount);
        $this->assertNotSame('Tampered Vendor', $bill->fresh()->vendor_name);

        $this->actingAs($this->admin())->post(route('accounting.bills.withdraw', $bill))->assertRedirect();
        $this->assertSame('open', $bill->fresh()->status);

        // Withdrawn, the bill is the requester's again.
        $this->actingAs($this->admin())->put(route('accounting.bills.update', $bill), [
            'vendor_name' => 'Corrected Vendor',
            'supplier_id' => null,
            'bill_date' => now()->toDateString(),
            'currency' => 'USD',
            'lines' => [
                [
                    'account_id' => Account::where('code', '6000')->firstOrFail()->id,
                    'amount' => '80.00',
                ],
            ],
        ])->assertRedirect();

        $this->assertSame('80.00', $bill->fresh()->amount);
    }

    public function test_voiding_an_awaiting_approval_bill_retires_the_request(): void
    {
        $bill = $this->storedBill($this->admin());

        $this->actingAs($this->admin())->post(route('accounting.bills.submit', $bill))->assertRedirect();

        $this->actingAs($this->admin())->patch(route('accounting.bills.status', $bill), ['status' => 'void'])
            ->assertRedirect();

        $this->assertSame('void', $bill->fresh()->status);

        // No live request survives the void — the (now retired) request is
        // read off the history row, not off liveApproval() which is empty by
        // design once a decision or cancellation has landed.
        $this->assertNull($bill->liveApproval());
        $this->assertSame('cancelled', $bill->approvalHistory()->first()->status);

        // And the voided bill never comes back: a decision cannot resurrect
        // what was cancelled.
        $this->actingAs($this->admin())
            ->post(route('accounting.bills.approve', $bill->fresh()))
            ->assertRedirect();
        $this->assertSame('void', $bill->fresh()->status);
    }

    // ── Payment lifecycle after approval ────────────────────────────────────

    public function test_an_approved_bill_takes_partial_and_final_payments(): void
    {
        $bill = $this->storedBill($this->admin());

        $this->actingAs($this->admin())->post(route('accounting.bills.submit', $bill))->assertRedirect();
        $this->actingAs($this->admin())->post(route('accounting.bills.approve', $bill))->assertRedirect();

        $this->pay($this->admin(), $bill, '40.00')->assertRedirect();
        $this->assertSame('partially_paid', $bill->fresh()->status);
        $this->assertSame('40.00', $bill->fresh()->paid_amount);

        $this->pay($this->admin(), $bill, '60.00')->assertRedirect();
        $this->assertSame('paid', $bill->fresh()->status);
        $this->assertSame('100.00', $bill->fresh()->paid_amount);
    }

    // ── The screen reads honestly ───────────────────────────────────────────

    public function test_the_show_screen_tells_each_operator_their_side_of_the_request(): void
    {
        $accountant = $this->accountant();
        $bill = $this->storedBill($accountant);

        $this->actingAs($accountant)->post(route('accounting.bills.submit', $bill))->assertRedirect();

        // When the maker looks at it, they are told plainly they cannot be
        // both sides of the transaction.
        $this->actingAs($accountant)->get(route('accounting.bills.show', $bill))
            ->assertOk()
            ->assertSee('someone else must decide', false)
            ->assertSee('Awaiting approval', false);

        // The Admin (the decider) sees the decision controls.
        $this->actingAs($this->admin())->get(route('accounting.bills.show', $bill))
            ->assertOk()
            ->assertSee('Approve', false);
    }

    public function test_the_screen_presents_the_open_and_self_approvable_states_honestly(): void
    {
        $admin = $this->admin();

        $bill = $this->storedBill($admin);

        // An un-approved bill offers submission — the payment form is not
        // even on the screen, because that money is not payable yet.
        $this->actingAs($admin)->get(route('accounting.bills.show', $bill))
            ->assertOk()
            ->assertSee('Submit for approval', false)
            ->assertDontSee('Record payment', false);

        $this->actingAs($admin)->post(route('accounting.bills.submit', $bill))->assertRedirect();

        // Admin viewing THEIR OWN request: the self-approve escape hatch is
        // explained before the approval buttons are offered.
        $this->actingAs($admin)->get(route('accounting.bills.show', $bill))
            ->assertOk()
            ->assertSee('You hold self-approval for bills', false);
    }
}
