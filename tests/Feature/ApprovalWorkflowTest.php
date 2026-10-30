<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\ExpenseClaim;
use App\Models\User;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * The approval workflow.
 *
 * Before this there was no approval engine at all. Each module carried its own
 * status machine and ExpenseClaimController::updateStatus() let the caller pick a
 * target status directly -- including 'reimbursed', from 'pending'. It also
 * stamped reviewed_by as a side effect when reimbursing an unapproved claim, so
 * one request could pay out money nobody had approved, approved by the same
 * person, with a timestamp on it.
 *
 * These tests pin the controls a business is actually buying when it asks for
 * approvals:
 *
 *  - a claim cannot be paid out before it is approved
 *  - the person who raised a request cannot decide it
 *  - a pending document cannot be edited underneath the approver
 *  - a rejection survives a resubmission
 *  - a decision cannot be taken twice
 */
class ApprovalWorkflowTest extends TestCase
{
    use SeedsDatabase;

    private ?User $requester = null;

    private ?User $approver = null;

    private ?User $maker = null;

    /**
     * Memoised: userForRole() creates the row, so calling it twice in one test
     * collides on the unique email.
     */
    private function requester(): User
    {
        return $this->requester ??= $this->userForRole('Admin');
    }

    /**
     * A requester who holds no approval permissions at all.
     *
     * Deliberately NOT the Admin: expense claims grant the Admin a
     * self-approve permission (the one-finance-office escape hatch), so the
     * maker-checker invariant below must be proved against a plain user.
     */
    private function maker(): User
    {
        return $this->maker ??= $this->staffUser('Unequipped maker');
    }

    private function approver(): User
    {
        return $this->approver ??= $this->staffUser('Approver');
    }

    /**
     * A second ordinary user who is not the requester.
     */
    private function staffUser(string $name): User
    {
        static $counter = 0;
        $counter++;

        return User::create([
            'name' => $name,
            'email' => str($name)->slug().$counter.'@approvals.test',
            'password' => bcrypt('secret1234'),
        ]);
    }

    private function claim(array $overrides = []): ExpenseClaim
    {
        return ExpenseClaim::create(array_merge([
            'number' => 'EXP-'.uniqid(),
            'employee_name' => 'Jane Doe',
            'expense_date' => '2026-01-10',
            'expense_type' => 'travel',
            'merchant' => 'Rail Co',
            'amount' => '250.00',
            'currency' => 'PKR',
            'status' => 'pending',
        ], $overrides));
    }

    public function test_a_new_claim_is_draft_and_submittable(): void
    {
        $claim = $this->claim();

        $this->assertTrue($claim->canBeSubmitted());
        $this->assertFalse($claim->isPendingApproval());
        $this->assertCount(0, $claim->approvalHistory());
    }

    public function test_submitting_raises_a_request_and_moves_the_status(): void
    {
        $claim = $this->claim();

        $request = $claim->submitForApproval($this->requester(), 1, 'Travel for the January visit');

        $this->assertTrue($request->isPending());
        $this->assertSame($this->requester()->id, $request->requested_by);
        $this->assertSame('pending_approval', $claim->fresh()->status);
        $this->assertTrue($claim->fresh()->isPendingApproval());
    }

    public function test_approving_records_who_decided_and_when(): void
    {
        $claim = $this->claim();
        $claim->submitForApproval($this->requester());

        $request = $claim->approve($this->approver(), 'Within policy');

        $this->assertTrue($request->isApproved());
        $this->assertSame($this->approver()->id, $request->decided_by);
        $this->assertNotNull($request->decided_at);
        $this->assertSame('approved', $claim->fresh()->status);
    }

    /**
     * The control that makes an approval step worth having. Without it the
     * request is a second save button, and the trail claims a review that did
     * not happen.
     */
    public function test_the_requester_cannot_approve_their_own_request(): void
    {
        $claim = $this->claim();
        $claim->submitForApproval($this->maker());

        $this->expectException(\RuntimeException::class);
        $claim->approve($this->maker());
    }

    public function test_the_requester_cannot_reject_their_own_request(): void
    {
        $claim = $this->claim();
        $claim->submitForApproval($this->maker());

        $this->expectException(\RuntimeException::class);
        $claim->reject($this->maker(), 'changed my mind');
    }

    public function test_a_failed_self_approval_leaves_the_request_live(): void
    {
        $claim = $this->claim();
        $claim->submitForApproval($this->maker());

        try {
            $claim->approve($this->maker());
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertTrue(
            $claim->fresh()->liveApproval()?->isPending(),
            'a refused self-approval consumed the request',
        );
    }

    /**
     * The money control. The old updateStatus() allowed paying out a claim that
     * had never been approved.
     */
    public function test_an_unapproved_claim_cannot_be_reimbursed(): void
    {
        $claim = $this->claim();

        $this->expectException(\RuntimeException::class);
        $claim->markReimbursed($this->approver());
    }

    public function test_an_approved_claim_can_be_reimbursed(): void
    {
        $claim = $this->claim();
        $claim->submitForApproval($this->requester());
        $claim->approve($this->approver());

        $claim->markReimbursed($this->approver());

        $this->assertSame('reimbursed', $claim->fresh()->status);
        $this->assertNotNull($claim->fresh()->reimbursed_at);
    }

    /**
     * A document can be edited underneath an approver otherwise, who would then
     * be signing off figures they never saw.
     */
    public function test_a_pending_claim_cannot_be_edited(): void
    {
        $claim = $this->claim();
        $claim->submitForApproval($this->requester());

        $this->expectException(\RuntimeException::class);
        $claim->update(['amount' => '9999.00']);
    }

    public function test_a_pending_claim_cannot_be_deleted(): void
    {
        $claim = $this->claim();
        $claim->submitForApproval($this->requester());

        // Deletion bypasses update(), so the guard has to be on the event.
        $this->expectException(\RuntimeException::class);
        $claim->delete();
    }

    /**
     * A rejection must remain in the trail after a resubmission. Overwriting the
     * status is not an audit trail.
     */
    public function test_a_rejection_survives_a_resubmission(): void
    {
        $claim = $this->claim();
        $claim->submitForApproval($this->requester());
        $claim->reject($this->approver(), 'Receipt missing');

        $this->assertSame('pending', $claim->fresh()->status, 'a rejected claim must become editable again');

        $claim->submitForApproval($this->requester());

        $history = $claim->approvalHistory();
        $this->assertCount(2, $history);
        $this->assertTrue($history->first()->isRejected());
        $this->assertSame('Receipt missing', $history->first()->comment);
        $this->assertTrue($history->last()->isPending());
    }

    public function test_a_request_cannot_be_raised_twice_while_one_is_live(): void
    {
        $claim = $this->claim();
        $claim->submitForApproval($this->requester());

        $this->expectException(\RuntimeException::class);
        $claim->submitForApproval($this->requester());
    }

    public function test_a_decision_cannot_be_taken_twice(): void
    {
        $claim = $this->claim();
        $claim->submitForApproval($this->requester());
        $claim->approve($this->approver());

        $this->expectException(\RuntimeException::class);
        $claim->reject($this->approver(), 'changed my mind after all');
    }

    public function test_a_requester_can_withdraw_their_own_request(): void
    {
        $claim = $this->claim();
        $claim->submitForApproval($this->requester());

        $claim->withdrawApproval($this->requester());

        $this->assertSame('pending', $claim->fresh()->status);
        $this->assertNull($claim->fresh()->liveApproval());
        $this->assertSame('cancelled', $claim->approvalHistory()->first()->status);
    }

    public function test_someone_else_cannot_withdraw_a_request(): void
    {
        $claim = $this->claim();
        $claim->submitForApproval($this->requester());

        $this->expectException(\RuntimeException::class);
        $claim->withdrawApproval($this->approver());
    }

    public function test_an_approved_claim_can_be_edited_again(): void
    {
        $claim = $this->claim();
        $claim->submitForApproval($this->requester());
        $claim->approve($this->approver());

        $claim->update(['merchant' => 'Rail Co (corrected)']);

        $this->assertSame('Rail Co (corrected)', $claim->fresh()->merchant);
    }

    public function test_the_approver_needs_a_distinct_permission(): void
    {
        $this->assertContains('accounting.expense_claims.approve', \App\Support\Permissions::all());
        $this->assertContains('accounting.expense_claims.reimburse', \App\Support\Permissions::all());

        $routes = collect(app('router')->getRoutes())->mapWithKeys(fn ($r) => [
            $r->getName() => $r->gatherMiddleware(),
        ]);

        $this->assertContains('permission:accounting.expense_claims.approve', $routes['accounting.expense_claims.approve']);
        $this->assertContains('permission:accounting.expense_claims.reimburse', $routes['accounting.expense_claims.reimburse']);
    }

    /**
     * The old single-status endpoint is gone. It is the route that let one
     * request pay out an unapproved claim.
     */
    public function test_the_free_form_status_endpoint_no_longer_exists(): void
    {
        $names = collect(app('router')->getRoutes())->map(fn ($r) => $r->getName());

        $this->assertFalse(
            $names->contains('accounting.expense_claims.status'),
            'the free-form status endpoint is back; it allowed paying out an unapproved claim',
        );

        $this->assertTrue($names->contains('accounting.expense_claims.submit'));
        $this->assertTrue($names->contains('accounting.expense_claims.approve'));
        $this->assertTrue($names->contains('accounting.expense_claims.reimburse'));
    }

    public function test_the_trail_survives_the_document_being_deleted(): void
    {
        $claim = $this->claim();
        $claim->submitForApproval($this->requester());
        $claim->reject($this->approver(), 'Duplicate receipt');

        // Soft delete keeps the rows, which is the point of recording them
        // separately from the document.
        $claim->delete();

        $this->assertSame(1, ApprovalRequest::where('approvable_id', $claim->id)->count());
    }

    public function test_waiting_days_is_reported_for_an_aged_inbox(): void
    {
        $claim = $this->claim();
        $request = $claim->submitForApproval($this->requester());

        $request->forceFill(['requested_at' => now()->subDays(9)])->save();

        $this->assertSame(9, $claim->fresh()->approvalWaitingDays());
        $this->assertSame(1, ApprovalRequest::agedOver(7)->count());
    }
}