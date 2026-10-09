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
        'number', 'employee_name', 'expense_date', 'expense_type', 'merchant',
        'amount', 'currency', 'status', 'notes', 'reviewed_by', 'reviewed_at', 'reimbursed_at',
    ];

    protected function casts(): array
    {
        return [
            'expense_date' => 'date',
            'amount' => 'decimal:2',
            'reviewed_at' => 'datetime',
            'reimbursed_at' => 'datetime',
        ];
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

    public static function statusOptions(): array
    {
        return ['pending', 'pending_approval', 'approved', 'rejected', 'reimbursed'];
    }
}