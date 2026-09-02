<x-app-layout :pageTitle="'Leave Request'">
    <x-slot name="header">
        <x-page-header :title="$leave->employee?->fullName()" :description="$leave->leave_type.' leave · '.$leave->days.' day(s)'" icon="calendar">
            <x-slot name="actions">
                <x-button href="{{ route('employees.leave.index') }}" variant="ghost" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-card title="Leave details">
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between"><dt class="text-ink-faint">Employee</dt><dd class="text-ink">{{ $leave->employee?->fullName() ?: '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-faint">Type</dt><dd class="text-ink">{{ ucfirst($leave->leave_type) }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-faint">From</dt><dd class="text-ink">{{ $leave->start_date?->format('M d, Y') ?: '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-faint">To</dt><dd class="text-ink">{{ $leave->end_date?->format('M d, Y') ?: '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-faint">Days</dt><dd class="text-ink">{{ $leave->days }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-faint">Status</dt>
                    <dd>
                        <x-badge :color="match ($leave->status) { 'approved' => 'success', 'rejected' => 'danger', 'cancelled' => 'neutral', default => 'warning' }" dot>
                            {{ ucfirst($leave->status) }}
                        </x-badge>
                    </dd>
                </div>
                <div class="flex justify-between"><dt class="text-ink-faint">Requested</dt><dd class="text-ink">{{ $leave->created_at?->format('M d, Y H:i') ?: '—' }}</dd></div>
                @if ($leave->reviewer)
                    <div class="flex justify-between"><dt class="text-ink-faint">Reviewed by</dt><dd class="text-ink">{{ $leave->reviewer->name }}</dd></div>
                @endif
                @if ($leave->reviewed_at)
                    <div class="flex justify-between"><dt class="text-ink-faint">Reviewed at</dt><dd class="text-ink">{{ $leave->reviewed_at?->format('M d, Y H:i') }}</dd></div>
                @endif
                @if ($leave->review_notes)
                    <div class="border-t border-line pt-3"><dt class="text-ink-faint">Review notes</dt><dd class="mt-1 whitespace-pre-line text-ink-soft">{{ $leave->review_notes }}</dd></div>
                @endif
            </dl>
        </x-card>

        <div class="lg:col-span-2">
            <x-card title="Reason">
                <p class="whitespace-pre-line text-sm text-ink-soft">{{ $leave->reason }}</p>
            </x-card>

            @if ($leave->status === 'pending')
                <div class="mt-6">
                    @php
                        $isManager = auth()->user()->isAdmin() || auth()->user()->hasRole('HR');
                        $isOwner = (int) $leave->employee_id === (int) auth()->user()?->employee?->id;
                    @endphp
                    @if ($isManager && auth()->user()->can('employees.leave_requests.approve'))
                        <x-card title="Decision" description="Approve or reject this leave request.">
                            <div class="flex flex-wrap items-end gap-3">
                                <form method="POST" action="{{ route('employees.leave.approve', $leave) }}">
                                    @csrf
                                    <x-button type="submit" variant="success" icon="check">Approve</x-button>
                                </form>
                                <form method="POST" action="{{ route('employees.leave.reject', $leave) }}" class="flex flex-wrap items-end gap-3">
                                    @csrf
                                    <div class="min-w-[240px] flex-1">
                                        <x-input name="review_notes" label="Rejection reason" required size="sm" placeholder="Why is this request rejected?" :error="$errors->first('review_notes')" />
                                    </div>
                                    <x-button type="submit" variant="danger-secondary" size="sm" icon="x">Reject</x-button>
                                </form>
                            </div>
                        </x-card>
                    @elseif ($isOwner && auth()->user()->can('employees.my_leave.cancel'))
                        <x-card title="Cancel request" description="Withdraw this request before HR responds.">
                            <form method="POST" action="{{ route('employees.leave.my.cancel', $leave) }}" onsubmit="return confirm('Cancel this leave request?')">
                                @csrf
                                <x-button type="submit" variant="danger-secondary" icon="x">Cancel request</x-button>
                            </form>
                        </x-card>
                    @endif
                </div>
            @endif
        </div>
    </div>
</x-app-layout>