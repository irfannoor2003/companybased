<?php

namespace App\Models;

use App\Models\Concerns\Approvable;
use App\Models\Concerns\Auditable;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Bill extends Model
{
    use Approvable;
    use Auditable;
    use SoftDeletes;

    protected $auditModule = 'accounting';

    protected $fillable = [
        'number', 'vendor_name', 'supplier_id', 'bill_date', 'due_date',
        'amount', 'paid_amount', 'currency', 'status', 'reference', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'bill_date' => 'date',
            'due_date' => 'date',
            'amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
        ];
    }

    /**
     * The bill lifecycle, mapped onto the shared approval engine.
     *
     * A bill is created `open` — the vendor's invoice arrived and is recorded.
     * Approval is what turns that record into a payable: submitted, the bill
     * moves to `pending_approval`, and only after someone grants approval does
     * it become `approved` and accept payments. The remaining statuses are the
     * payment lifecycle the bill already had — partially_paid, paid, void.
     *
     * Every status in this list is part of the real workflow: `partially_paid`
     * is what an approved bill carries mid-payment, and edits are refused once
     * a request is live so nobody pays figures the approver never saw.
     */
    public function submittableStatuses(): array
    {
        return ['open'];
    }

    /**
     * The self-approval override the Approvable trait consults.
     *
     * Deliberately not `accounting.bills.approve`: that grants deciding OTHERS'
     * bills and is the normal approver permission, while self-approval is the
     * narrower, audited escape hatch for the finance officer who both raises
     * and settles small bills — a one-person accounting office would otherwise
     * deadlock. It rides on the Admin role only; an Accountant's bills always
     * wait for the Admin to decide them.
     */
    protected function allowsSelfApproval(?User $decider): bool
    {
        return $decider !== null
            && $decider->can('accounting.bills.self_approve');
    }

    /**
     * Model-level locks, mirroring the expense claim: while a request is live
     * the bill cannot be edited (or deleted) by ANY path, not just the
     * controller — a direct model call would otherwise rewrite the figures the
     * approver is looking at.
     *
     * Both workflow engine writes are exempt by construction: submitForApproval
     * and the decisions change only `status`, which the trait's guard ignores,
     * and payments only reach a bill that has already been approved.
     */
    protected static function booted(): void
    {
        static::updating(function (self $bill) {
            $bill->guardAgainstEditWhilePending();
        });

        static::deleting(function (self $bill) {
            if ($bill->isPendingApproval()) {
                throw new \RuntimeException(
                    'This bill is awaiting approval and cannot be deleted. '
                    .'Withdraw the request or void the bill first.'
                );
            }
        });
    }

    public function items(): HasMany
    {
        return $this->hasMany(BillItem::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('number', 'like', "%{$term}%")
                ->orWhere('vendor_name', 'like', "%{$term}%")
                ->orWhere('reference', 'like', "%{$term}%");
        });
    }

    public static function statusOptions(): array
    {
        return ['draft', 'open', 'pending_approval', 'approved', 'partially_paid', 'paid', 'void'];
    }

    public function balance(): float
    {
        return round((float) $this->amount - (float) $this->paid_amount, 2);
    }

    public function isPaid(): bool
    {
        return $this->balance() <= 0;
    }
}