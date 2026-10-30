<x-app-layout :pageTitle="$bill->number">
    <x-slot name="header">
        <x-page-header :title="$bill->number" :description="$bill->vendor_name.' · '.$bill->bill_date->format('Y-m-d')" icon="invoice">
            <x-slot name="actions">
                @if (auth()->user()->can('accounting.bills.edit') && ! in_array($bill->status, ['paid', 'void', 'pending_approval', 'approved']))
                    <x-button href="{{ route('accounting.bills.edit', $bill) }}" variant="secondary" icon="edit">Edit</x-button>
                @endif
                <x-button href="{{ route('accounting.bills.index') }}" variant="ghost" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    @include('accounting._tabs')

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat-card label="Amount" :value="money($bill->amount, $bill->currency)" icon="invoice" tone="primary" />
        <x-stat-card label="Paid" :value="money($bill->paid_amount, $bill->currency)" icon="check" tone="success" />
        <x-stat-card label="Balance" :value="money($bill->balance(), $bill->currency)" icon="clock" :tone="$bill->balance() > 0 ? 'warning' : 'success'" />
        <x-stat-card label="Status" :value="ucfirst(str_replace('_', ' ', $bill->status))" icon="tag" :tone="$bill->status === 'paid' ? 'success' : ($bill->status === 'void' ? 'danger' : 'info')" />
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-card title="Line items" :padding="false" class="lg:col-span-2">
            @if ($bill->items->isEmpty())
                <x-empty-state icon="document" title="No line items" description="This bill has no itemized lines." />
            @else
                <div class="table-wrap !border-0 !rounded-none">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th>Account</th>
                                <th>Description</th>
                                <th class="text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($bill->items as $item)
                                <tr>
                                    <td>
                                        <span class="font-medium text-ink">{{ $item->account?->name }}</span>
                                        <span class="block font-mono text-xs text-ink-faint">{{ $item->account?->code }}</span>
                                    </td>
                                    <td class="text-ink-soft">{{ $item->description ?: '—' }}</td>
                                    <td class="text-right font-medium text-ink">{{ money($item->amount, $bill->currency) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="2" class="text-right font-semibold text-ink">Total</td>
                                <td class="text-right font-semibold text-ink">{{ money($bill->amount, $bill->currency) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </x-card>

        <div class="space-y-6">
            <x-card title="Bill details">
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between"><dt class="text-ink-faint">Number</dt><dd class="font-mono text-ink">{{ $bill->number }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-faint">Vendor</dt><dd class="text-ink">{{ $bill->vendor_name }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-faint">Bill date</dt><dd class="text-ink">{{ $bill->bill_date->format('Y-m-d') }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-faint">Due date</dt><dd class="text-ink">{{ $bill->due_date?->format('Y-m-d') ?: '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-faint">Reference</dt><dd class="text-ink">{{ $bill->reference ?: '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-faint">Status</dt><dd><x-accounting.status-badge :status="$bill->status" /></dd></div>
                </dl>
            </x-card>

            {{--
                The approval card. An `open` bill is recorded but not yet payable;
                submission hands it to whoever holds approval, and only an
                `approved` bill accepts payments. The same maker-checker
                presentation as the expense claims: the person who raised the
                request is told so plainly, and self-approval is offered only to
                someone who holds the dedicated `bills.self_approve` permission.
            --}}
            <x-card title="Approval">
                <div class="space-y-4">
                    @if ($bill->isPendingApproval())
                        @php $live = $bill->liveApproval(); @endphp

                        <div class="rounded-lg border border-line bg-surface-muted p-4">
                            <div class="text-sm font-medium text-ink">
                                Awaiting approval &middot; requested by {{ $live?->requester?->name ?? 'unknown' }}
                            </div>
                            <div class="mt-1 text-xs text-ink-faint">
                                Submitted {{ $live?->requested_at?->diffForHumans() }} &middot;
                                waiting {{ $bill->approvalWaitingDays() }} {{ Str::plural('day', $bill->approvalWaitingDays()) }}
                            </div>
                        </div>

                        @if (auth()->user()->can('accounting.bills.approve'))
                            @php($raisedByMe = $live?->requested_by === auth()->id())
                            @php($maySelfApprove = auth()->user()->can('accounting.bills.self_approve'))
                            @if ($raisedByMe && ! $maySelfApprove)
                                <x-alert type="info">
                                    You raised this request, so someone else must decide it.
                                    Separation of duties is enforced by the system, not by policy.
                                </x-alert>
                            @else
                                @if ($raisedByMe && $maySelfApprove)
                                    <x-alert type="info" class="mb-3">
                                        You hold self-approval for bills, so you may decide your own request. The trail records that requester and decider were the same person.
                                    </x-alert>
                                @endif
                                <form method="POST" action="{{ route('accounting.bills.approve', $bill) }}" class="space-y-3">
                                    @csrf
                                    <x-textarea name="comment" label="Comment" rows="2" placeholder="Optional note recorded against the approval." />
                                    <div class="flex gap-2">
                                        <x-button type="submit" variant="success" size="sm" icon="check">Approve</x-button>
                                    </div>
                                </form>

                                <form method="POST" action="{{ route('accounting.bills.reject', $bill) }}" class="space-y-3">
                                    @csrf
                                    <x-textarea name="comment" label="Reason for rejection" rows="2" required
                                                placeholder="Required. The bill returns to open and can be corrected and resubmitted." />
                                    <x-button type="submit" variant="danger" size="sm" icon="trash">Reject</x-button>
                                </form>
                            @endif

                            {{--
                                Withdrawal belongs to the requester, whoever else can
                                approve — its own permission gate instead of the
                                approver's, so someone who raises a bill and only
                                holds edit can still pull their request back.
                            --}}
                            @if ($live?->requested_by === auth()->id() && auth()->user()->can('accounting.bills.edit'))
                                <form method="POST" action="{{ route('accounting.bills.withdraw', $bill) }}">
                                    @csrf
                                    <x-button type="submit" variant="ghost" size="sm">Withdraw request</x-button>
                                </form>
                            @endif
                        @endif
                    @elseif ($bill->canBeSubmitted())
                        <p class="text-sm text-ink-soft">
                            This bill has not been approved yet. It cannot be paid until it has been.
                        </p>
                        @if (auth()->user()->can('accounting.bills.edit'))
                            <form method="POST" action="{{ route('accounting.bills.submit', $bill) }}" class="space-y-3">
                                @csrf
                                <x-textarea name="comment" label="Note for the approver" rows="2" />
                                <x-button type="submit" size="sm" icon="check">Submit for approval</x-button>
                            </form>
                        @endif
                    @elseif ($bill->isApproved())
                        <p class="text-sm text-ink-soft">
                            Approved. Payments can now be recorded against it.
                        </p>
                        {{--
                            The newest APPROVED row from the trail. Self-approval is read
                            off the row itself — requester and decider are the same
                            person — so the note never claims a control the trail
                            cannot back up.
                        --}}
                        @php($approvedRow = $bill->approvalHistory()->where('status', 'approved')->last())
                        @if ($approvedRow?->decider?->is(auth()->user()))
                            <p class="mt-1 text-xs text-ink-faint">
                                You approved this bill{{ $approvedRow->requested_by === $approvedRow->decided_by ? ', deciding your own request under your self-approval permission' : '' }} — the trail records who and when.
                            </p>
                        @endif
                    @endif
                </div>
            </x-card>

            @if (auth()->user()->can('accounting.bills.record_payment') && in_array($bill->status, ['approved', 'partially_paid']))
                <x-card title="Record payment" description="Apply against the outstanding balance">
                    <form method="POST" action="{{ route('accounting.bills.payment', $bill) }}" class="space-y-3">
                        @csrf
                        <div class="grid grid-cols-2 gap-3">
                            <x-input name="amount" label="Amount" type="number" step="0.01" min="0" required value="{{ old('amount', $bill->balance()) }}" />
                            <x-input name="paid_at" label="Date" type="date" required value="{{ old('paid_at', now()->toDateString()) }}" />
                        </div>
                        <x-button type="submit" size="sm" icon="check">Record payment</x-button>
                    </form>
                </x-card>
            @endif

            @if (auth()->user()->can('accounting.bills.edit') && ! in_array($bill->status, ['paid', 'void']))
                <x-card title="Void bill" description="Permanently cancel this bill">
                    <form method="POST" action="{{ route('accounting.bills.status', $bill) }}"
                        onsubmit="return confirm('Void bill {{ $bill->number }}?');">
                        @csrf
                        <input type="hidden" name="status" value="void">
                        <x-button type="submit" variant="danger-secondary" size="sm" icon="x">Void bill</x-button>
                    </form>
                </x-card>
            @endif
        </div>
    </div>
</x-app-layout>