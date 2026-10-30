<?php

namespace App\Models;

use App\Models\Concerns\Approvable;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ExpenseClaim extends Model
{
    use Approvable;
    use Auditable;
    use SoftDeletes;

    protected $auditModule = 'accounting';

    /**
     * The expense lifecycle, mapped onto the shared approval engine.
     *
     * `pending` and `rejected` are both submittable: a rejected claim goes back to
     * an editable state so the employee can correct it and resubmit, and the
     * earlier refusal stays in the approval trail rather than being overwritten.
     *
     * Overridden as methods because PHP forbids a class from redeclaring a trait
     * property with a different default, and this is the trait's whole purpose.
     */
public function submittableStatuses(): array
    {
        return ['pending', 'rejected'];
    }

    protected $fillable = [
        'number', 'employee_name', 'expense_date', 'expense_type', 'expense_category_id',
        'bank_account_id', 'merchant', 'amount', 'discount_percent', 'discount_amount', 'currency', 'status', 'notes',
        'reviewed_by', 'reviewed_at', 'reimbursed_at',
    ];

    protected function casts(): array
    {
        return [
            'expense_date' => 'date',
            'amount' => 'decimal:2',
            'discount_percent' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'reviewed_at' => 'datetime',
            'reimbursed_at' => 'datetime',
        ];
    }

    /**
     * The claim's amount after the discount off the billed figure.
     *
     * A claim of 100 with a 10% discount -- or a specific 10 off the bill the
     * other party gave the employee -- is really a claim of 90. This is the figure
     * that leaves the account on approval, so every consumer that moves money
     * (the bank withdrawal at approval, the journal, the reimbursement) reads it
     * rather than the raw billed amount and the deduction silently keeps the
     * undiscounted total.
     */
    public function effectiveAmount(): float
    {
        $amount = (float) $this->amount;
        $discount = (float) ($this->discount_amount ?? 0);

        if ($discount > 0.0) {
            return round($amount - $discount, 2);
        }

        $percent = (float) ($this->discount_percent ?? 0);

        return round($amount - ($amount * ($percent / 100)), 2);
    }

    /**
     * The admin-created category the claim charges.
     */
    public function expenseCategory(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class);
    }

    /**
     * The bank account — or petty-cash account — the claim is paid from.
     *
     * An approval withdraws the claim's amount from this account, which is why
     * it never dangles loosely: a null here means the claim is paid outside the
     * tracked accounts and posts no deduction.
     */
    public function paidFrom(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    /**
     * A claim cannot be edited while it is awaiting a decision, otherwise the
     * approver would be signing off figures the employee had since changed.
     *
     * Deletion is guarded as well as editing: a pending claim that can be deleted
     * takes its own approval trail with it, which is the one thing the trail
     * exists to prevent.
     */
    protected static function booted(): void
    {
        static::updating(function (self $claim) {
            $claim->guardAgainstEditWhilePending();
        });

        static::deleting(function (self $claim) {
            if ($claim->isPendingApproval()) {
                throw new \RuntimeException(
                    'This claim is awaiting approval and cannot be deleted. '
                    .'Withdraw the request first.'
                );
            }
        });
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Mark a claim as reimbursed, refusing one that was never approved.
     *
     * Paying out a claim nobody approved is the exact loss the approval workflow
     * exists to prevent, and the old status machine allowed it: `approved` and
     * `reimbursed` were peer statuses with nothing guarding the transition.
     */
    public function markReimbursed(?User $user = null): self
    {
        if (! $this->isApproved() && $this->status !== 'reimbursed') {
            throw new \RuntimeException(
                'Only an approved claim can be reimbursed. This claim is "'.$this->status.'".'
            );
        }

        $this->update([
            'status' => 'reimbursed',
            'reimbursed_at' => now(),
            'reviewed_by' => $user?->id ?? $this->reviewed_by,
        ]);

        return $this;
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('number', 'like', "%{$term}%")
                ->orWhere('employee_name', 'like', "%{$term}%")
                ->orWhere('merchant', 'like', "%{$term}%");
        });
    }

    /**
     * Claims waiting longer than the given number of days.
     *
     * An approvals inbox that cannot be sorted by age is not an approvals inbox.
     */
    public function scopeAwaitingOver(Builder $query, int $days): Builder
    {
        return $query->where('status', 'pending_approval')
            ->whereHas('approvalRequests', fn ($q) => $q->where('status', 'pending')
                ->where('requested_at', '<=', now()->subDays($days)));
    }

    public static function typeOptions(): array
    {
        return ['travel', 'meals', 'fuel', 'supplies', 'software', 'other'];
    }

    /**
     * The self-approval override the Approvable trait consults.
     *
     * A claim raised by someone who also holds the DEDICATED self-approve
     * permission may be decided by that same person. It is deliberately not
     * `accounting.expense_claims.approve`: that grants approving OTHERS' claims
     * and is carried by Accountant and Admin alike, while self-approval is the
     * narrower, audited escape hatch for a deployment where the approver and the
     * claimant are the same finance office — so it rides on the Admin role, not
     * the Accountant's.
     */
    protected function allowsSelfApproval(?User $decider): bool
    {
        return $decider !== null
            && $decider->can('accounting.expense_claims.self_approve');
    }

    public static function statusOptions(): array
    {
        return ['pending', 'pending_approval', 'approved', 'rejected', 'reimbursed'];
    }
}