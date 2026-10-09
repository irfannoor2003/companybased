<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One request for a document to be approved, and the decision taken on it.
 *
 * A row per decision rather than columns on the document, so that rejecting and
 * resubmitting leaves the earlier refusal intact. Overwriting an approval column
 * is not an audit trail, and the refusal is the part an auditor asks for.
 */
class ApprovalRequest extends Model
{
    use Auditable;

    protected $fillable = [
        'step', 'status', 'requested_by', 'requested_at',
        'decided_by', 'decided_at', 'comment',
    ];

    // Approval decisions are themselves auditable: who asked, who decided and
    // when is the record a business is buying when it asks for approvals.
    protected $auditModule = 'accounting';

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    /**
     * How long the request has been waiting, for an ageing report.
     */
    public function waitingDays(): int
    {
        if (! $this->requested_at) {
            return 0;
        }

        return (int) $this->requested_at->diffInDays($this->decided_at ?? Carbon::now());
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    /**
     * Requests that have been waiting longer than the given number of days.
     *
     * Backed by a real query rather than filtering a loaded collection: an
     * approvals inbox is exactly the list that gets slow, and the ageing view is
     * the one a manager runs when it is slow.
     */
    public function scopeAgedOver(Builder $query, int $days): Builder
    {
        return $query->where('status', 'pending')
            ->where('requested_at', '<=', Carbon::now()->subDays($days));
    }

    public static function statusOptions(): array
    {
        return ['pending', 'approved', 'rejected', 'cancelled'];
    }
}