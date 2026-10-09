<x-app-layout :pageTitle="$claim->number">
    <x-slot name="header">
        <x-page-header :title="$claim->number" :description="$claim->employee_name.' · '.$claim->expense_date->format('Y-m-d')" icon="money">
            <x-slot name="actions">
                @if (auth()->user()->can('accounting.expense_claims.edit') && $claim->status === 'pending')
                    <x-button href="{{ route('accounting.expense_claims.edit', $claim) }}" variant="secondary" icon="edit">Edit</x-button>
                @endif
                <x-button href="{{ route('accounting.expense_claims.index') }}" variant="ghost" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    @include('accounting._tabs')

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-card title="Claim details" class="lg:col-span-2">
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between"><dt class="text-ink-faint">Number</dt><dd class="font-mono text-ink">{{ $claim->number }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-faint">Employee</dt><dd class="text-ink">{{ $claim->employee_name }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-faint">Expense date</dt><dd class="text-ink">{{ $claim->expense_date->format('Y-m-d') }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-faint">Type</dt><dd class="text-ink">{{ ucfirst($claim->expense_type) }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-faint">Merchant</dt><dd class="text-ink">{{ $claim->merchant ?: '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-faint">Amount</dt><dd class="font-semibold text-ink">{{ money($claim->amount, $claim->currency) }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-faint">Status</dt><dd><x-accounting.status-badge :status="$claim->status" /></dd></div>
                <div class="flex justify-between"><dt class="text-ink-faint">Reviewed by</dt><dd class="text-ink">{{ $claim->reviewer?->name ?: '—' }}</dd></div>
                @if ($claim->notes)
                    <div class="border-t border-line pt-3"><dt class="text-ink-faint">Notes</dt><dd class="mt-1 text-ink-soft">{{ $claim->notes }}</dd></div>
                @endif
            </dl>
        </x-card>

        {{--
    Approval is explicit now: one action per transition, each behind its own
    permission, rather than a dropdown that let a single request move a claim
    straight to reimbursed.

    A claim awaiting a decision shows no editing link, because approving figures
    the employee changed afterwards would mean approving something nobody read.
--}}
<x-card title="Approval">
    <div class="space-y-4">
        @if ($claim->isPendingApproval())
            @php $live = $claim->liveApproval(); @endphp

            <div class="rounded-lg border border-line bg-surface-muted p-4">
                <div class="text-sm font-medium text-ink">
                    Awaiting approval &middot; requested by {{ $live?->requester?->name ?? 'unknown' }}
                </div>
                <div class="mt-1 text-xs text-ink-faint">
                    Submitted {{ $live?->requested_at?->diffForHumans() }} &middot;
                    waiting {{ $claim->approvalWaitingDays() }} {{ Str::plural('day', $claim->approvalWaitingDays()) }}
                </div>
            </div>

            @if (auth()->user()->can('accounting.expense_claims.approve'))
                @unless ($live?->requested_by === auth()->id())
                    <form method="POST" action="{{ route('accounting.expense_claims.approve', $claim) }}" class="space-y-3">
                        @csrf
                        <x-textarea name="comment" label="Comment" rows="2" placeholder="Optional note recorded against the approval." />
                        <div class="flex gap-2">
                            <x-button type="submit" variant="success" size="sm" icon="check">Approve</x-button>
                        </div>
                    </form>

                    <form method="POST" action="{{ route('accounting.expense_claims.reject', $claim) }}" class="space-y-3">
                        @csrf
                        <x-textarea name="comment" label="Reason for rejection" rows="2" required
                                    placeholder="Required. The employee sees this and can correct and resubmit." />
                        <x-button type="submit" variant="danger" size="sm" icon="trash">Reject</x-button>
                    </form>
                @else
                    <x-alert type="info">
                        You raised this request, so someone else must decide it.
                        Separation of duties is enforced by the system, not by policy.
                    </x-alert>
                @endunless

                <form method="POST" action="{{ route('accounting.expense_claims.withdraw', $claim) }}">
                    @csrf
                    <x-button type="submit" variant="ghost" size="sm">Withdraw request</x-button>
                </form>
            @endif
        @elseif ($claim->canBeSubmitted())
            <p class="text-sm text-ink-soft">
                This claim has not been submitted for approval yet. It cannot be reimbursed until it has been.
            </p>
            @if (auth()->user()->can('accounting.expense_claims.edit'))
                <form method="POST" action="{{ route('accounting.expense_claims.submit', $claim) }}" class="space-y-3">
                    @csrf
                    <x-textarea name="comment" label="Note for the approver" rows="2" />
                    <x-button type="submit" size="sm" icon="check">Submit for approval</x-button>
                </form>
            @endif
        @elseif ($claim->status === 'approved')
            <p class="text-sm text-ink-soft">Approved. Payment can now be recorded.</p>
            @if (auth()->user()->can('accounting.expense_claims.reimburse'))
                <form method="POST" action="{{ route('accounting.expense_claims.reimburse', $claim) }}">
                    @csrf
                    <x-button type="submit" variant="success" size="sm" icon="check">Mark as reimbursed</x-button>
                </form>
            @endif
        @endif

        {{-- The trail. Rejections stay visible after a resubmission: that history
             is the point of an approval record. --}}
        @if ($claim->approvalRequests->isNotEmpty())
            <div class="border-t border-line pt-4">
                <h4 class="text-xs font-medium uppercase tracking-wide text-ink-faint">Approval history</h4>
                <ul class="mt-3 space-y-3">
                    @foreach ($claim->approvalHistory() as $request)
                        <li class="text-xs">
                            <span class="font-medium text-ink">
                                {{ ucfirst($request->status) }}
                                @if ($request->status === 'pending')
                                    <span class="text-ink-faint">(live)</span>
                                @endif
                            </span>
                            <div class="text-ink-faint">
                                Requested by {{ $request->requester?->name ?? 'system' }}
                                {{ $request->requested_at?->format('j M Y H:i') }}
                                @if ($request->decided_by)
                                    &middot; decided by {{ $request->decider?->name }}
                                    {{ $request->decided_at?->format('j M Y H:i') }}
                                @endif
                            </div>
                            @if ($request->comment)
                                <div class="mt-1 text-ink-soft">{{ $request->comment }}</div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
</x-card>
    </div>
</x-app-layout>