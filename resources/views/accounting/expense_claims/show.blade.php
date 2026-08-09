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

        <x-card title="Review" description="Advance the claim through the workflow">
            <form method="POST" action="{{ route('accounting.expense_claims.status', $claim) }}" class="space-y-3">
                @csrf
                <x-select name="status" label="Move to" size="sm">
                    @foreach (['approved', 'rejected', 'reimbursed'] as $status)
                        <option value="{{ $status }}" @selected($claim->status === $status) @if ($claim->status === $status) disabled @endif>{{ ucfirst($status) }}</option>
                    @endforeach
                </x-select>
                @if ($claim->status === 'reimbursed')
                    <x-button type="submit" size="sm" icon="check" disabled>Update status</x-button>
                @else
                    <x-button type="submit" size="sm" icon="check">Update status</x-button>
                @endif
                <p class="text-xs text-ink-faint">Pending → approved/rejected → reimbursed.</p>
            </form>
        </x-card>
    </div>
</x-app-layout>