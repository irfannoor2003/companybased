<?php

namespace App\Models\Concerns;

use App\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * A shared approval lifecycle for any document in any module.
 *
 * The application previously had no approval engine. Each module invented its own
 * status machine -- ExpenseClaim with reviewed_by, PurchaseOrder with confirmed,
 * SalesOrder with confirmed -- none of them recorded who asked or who decided, and
 * none of them stopped the maker from approving their own work.
 *
 * That last point is the reason this exists rather than a helper method. An
 * approval step where the requester can approve their own request is not an
 * approval step; it is a second save button. Maker-checker separation is the
 * control a business is actually buying when it asks for approvals.
 *
 * Lifecycle:
 *
 *   submitForApproval()   -> pending, and the document becomes locked to edits
 *   approve() / reject()  -> decided, recorded, with who and when
 *   withdraw()            -> the requester can pull it back while still pending
 *
 * A rejected document can be resubmitted, and the earlier rejection stays in the
 * trail. Resubmitting replaces nothing.
 *
 * @property string $status
 * @property-read Collection<int, ApprovalRequest> $approvalRequests
 */
trait Approvable
{
    /**
     * Statuses from which a document may be submitted.
     *
     * Methods rather than properties, because PHP refuses to let a class redeclare
     * a trait property with a different default -- and overriding these per module
     * is the entire point of the trait. Properties would make the first adopter
     * work and every module after it a fatal error.
     *
     * @return array<int, string>
     */
    public function submittableStatuses(): array
    {
        return ['draft'];
    }

    /**
     * Status a document carries while an approval request is live.
     */
    public function pendingStatus(): string
    {
        return 'pending_approval';
    }

    /**
     * Status applied when an approval is granted.
     */
    public function approvedStatus(): string
    {
        return 'approved';
    }

    /**
     * Raise a request for approval.
     *
     * @throws \RuntimeException when the document cannot be submitted, a request is
     *                           already live, or the requester has no permission
     */
    public function submitForApproval(?User $requester, int $step = 1, ?string $comment = null): ApprovalRequest
    {
        if (! $this->canBeSubmitted()) {
            throw new \RuntimeException(
                'A document in status "'.$this->status.'" cannot be submitted for approval.'
            );
        }

        /**
         * The database enforces one live request per step, but checking first
         * produces a message that names the problem instead of surfacing a
         * constraint violation as a 500.
         */
        if ($this->liveApprovalFor($step) !== null) {
            throw new \RuntimeException('This document already has a request awaiting a decision.');
        }

        /**
         * Built unsaved, then attached through the relation.
         *
         * Not ApprovalRequest::create(): that inserts immediately, and the morph
         * columns are only filled in by the relationship. Creating first would
         * attempt to insert approvable_type and approvable_id as null, which the
         * database refuses -- so the request would never be recorded at all.
         */
        $request = new ApprovalRequest([
            'step' => $step,
            'status' => 'pending',
            'requested_by' => $requester?->id,
            'requested_at' => now(),
            'comment' => $comment,
        ]);

        $this->approvalRequests()->save($request);

        $this->update(['status' => $this->pendingStatus()]);

        return $request->fresh();
    }

    /**
     * Grant approval.
     */
    public function approve(?User $decider, ?string $comment = null): ApprovalRequest
    {
        $request = $this->claimLiveApproval($decider, 'approve');

        $request->update([
            'status' => 'approved',
            'decided_by' => $decider?->id,
            'decided_at' => now(),
            'comment' => $comment,
        ]);

        $this->update(['status' => $this->approvedStatus()]);

        return $request->fresh();
    }

    /**
     * Refuse approval, recording why.
     *
     * The document goes back to a submittable status rather than to an approved
     * one, so the owner can correct it and resubmit -- and the refusal remains in
     * the trail beneath the new request.
     */
    public function reject(?User $decider, ?string $comment = null): ApprovalRequest
    {
        $request = $this->claimLiveApproval($decider, 'reject');

        $request->update([
            'status' => 'rejected',
            'decided_by' => $decider?->id,
            'decided_at' => now(),
            'comment' => $comment,
        ]);

        $this->update(['status' => $this->submittableStatuses()[0]]);

        return $request->fresh();
    }

    /**
     * Pull a live request back. Only the requester may do this.
     */
    public function withdrawApproval(?User $user): ApprovalRequest
    {
        $request = $this->liveApprovalFor();

        if ($request === null) {
            throw new \RuntimeException('There is no approval request awaiting a decision.');
        }

        if ($user !== null && $request->requested_by !== null && $request->requested_by !== $user->id) {
            throw new \RuntimeException('Only the person who requested approval can withdraw the request.');
        }

        $request->update([
            'status' => 'cancelled',
            'decided_by' => $user?->id,
            'decided_at' => now(),
        ]);

        $this->update(['status' => $this->submittableStatuses()[0]]);

        return $request->fresh();
    }

    public function isPendingApproval(): bool
    {
        return $this->status === $this->pendingStatus();
    }

    public function isApproved(): bool
    {
        return $this->status === $this->approvedStatus();
    }

    public function canBeSubmitted(): bool
    {
        return in_array($this->status, $this->submittableStatuses(), true);
    }

    /**
     * The full trail, oldest first. Never filtered: a document that was rejected
     * twice and then approved has three rows and the refusal is part of why it
     * was eventually allowed through.
     *
     * @return Collection<int, ApprovalRequest>
     */
    public function approvalHistory(): Collection
    {
        return $this->approvalRequests()->orderBy('id')->get();
    }

    /**
     * The live request for a step, if any.
     */
    public function liveApprovalFor(?int $step = null): ?ApprovalRequest
    {
        return $this->approvalRequests()
            ->where('status', 'pending')
            ->when($step !== null, fn ($q) => $q->where('step', $step))
            ->orderBy('step')
            ->first();
    }

    /**
     * The oldest live request, which is the one that must be decided first.
     */
    public function liveApproval(): ?ApprovalRequest
    {
        return $this->liveApprovalFor();
    }

    /**
     * Unordered on purpose.
     *
     * The relation used to declare latest('id'), which silently beat the
     * orderBy('id') in approvalHistory() -- appending a sort key to an existing
     * desc does not reverse it, so the trail came back newest-first and a
     * rejection read as if it had happened after the resubmission that followed
     * it. Callers order; the relation does not.
     */
    public function approvalRequests()
    {
        return $this->morphMany(ApprovalRequest::class, 'approvable');
    }

    /**
     * The maker-checker override.
     *
     * The default is FALSE and it stays false: maker-checker separation is the
     * control the approval engine exists to provide, and a trait default is
     * inherited by every adopting document, so the safe choice is the one that
     * requires no decision. A document type that legitimately needs self-approval
     * overrides this — ExpenseClaim does, because the Admin who raised a claim is
     * also finance, and denying them would stall single-finance deployments
     * forever. The override must EARN it — it receives the decider and is free to
     * refuse.
     */
    protected function allowsSelfApproval(?User $decider): bool
    {
        return false;
    }

    /**
     * Take the live request and check the decider is allowed to decide it.
     */
    private function claimLiveApproval(?User $decider, string $action): ApprovalRequest
    {
        $request = $this->liveApprovalFor();

        if ($request === null) {
            throw new \RuntimeException('There is no approval request awaiting a decision.');
        }

        /**
         * Maker-checker separation.
         *
         * Refused outright rather than warned about. A self-approval leaves a
         * request that appears to have been reviewed by someone, which is worse
         * than no review at all because the trail claims a control that did not
         * operate. A document type may override this refusal through
         * `allowsSelfApproval()` — the decider is handed over so the override
         * can gate on its own permission, and the trail is now the override's
         * responsibility to make honest (the decision is still recorded, with
         * requester and decider named even when both are the same person).
         */
        if ($decider !== null
            && $request->requested_by !== null
            && $request->requested_by === $decider->id
            && ! $this->allowsSelfApproval($decider)) {
            throw new \RuntimeException(
                'You cannot '.($action === 'approve' ? 'approve' : 'reject')
                .' a request you raised yourself. Separation of duties requires a different approver.'
            );
        }

        return $request;
    }

    /**
     * Refuse substantive edits once a document is awaiting a decision.
     *
     * Without this a document could be submitted, then edited underneath the
     * approver, who would be approving figures they never saw.
     *
     * Status itself is exempt. The approval flow is what moves a document between
     * pending and decided, so guarding the status column too would make the
     * workflow unable to run -- the request could be raised and then nothing
     * could ever decide it.
     */
    public function guardAgainstEditWhilePending(): void
    {
        if (! $this->isPendingApproval()) {
            return;
        }

        $substantive = array_diff(
            array_keys($this->getDirty()),
            ['status', 'updated_at'],
        );

        if ($substantive !== []) {
            throw new \RuntimeException(
                'This document is awaiting approval and cannot be changed (found: '
                .implode(', ', $substantive).'). Withdraw the request first.'
            );
        }
    }

    /**
     * How long the document has been waiting, for an approvals inbox.
     */
    public function approvalWaitingDays(): int
    {
        return $this->liveApprovalFor()?->waitingDays() ?? 0;
    }
}