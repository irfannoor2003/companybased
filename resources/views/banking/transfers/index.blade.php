<x-app-layout :pageTitle="'Bank transfers'">
    <x-slot name="header">
        <x-page-header title="Bank transfers" description="Move money between accounts." icon="arrow-right">
            <x-slot name="actions">
                @if (auth()->user()->can('banking.transfers.export'))
                    <x-export route="banking.transfers.export" />
                @endif
                @if (auth()->user()->can('banking.transfers.create'))
                    <x-button href="{{ route('banking.transfers.create') }}" icon="plus">New transfer</x-button>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    <x-card :padding="false">
        <form method="GET" action="{{ route('banking.transfers.index') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
            <div class="min-w-[200px] flex-1">
                <x-input name="search" label="Search" placeholder="Transfer number…" leadingIcon="search"
                    value="{{ request('search') }}" size="sm" />
            </div>
            <div class="w-52">
                <x-select name="account" label="Account" size="sm">
                    <option value="">All accounts</option>
                    @foreach ($accounts as $account)
                        <option value="{{ $account->id }}" @selected(request('account') == $account->id)>{{ $account->name }}</option>
                    @endforeach
                </x-select>
            </div>
            <div class="w-40">
                <x-select name="status" label="Status" size="sm">
                    <option value="">Any status</option>
                    @foreach (\App\Models\BankTransfer::statusOptions() as $status)
                        <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </x-select>
            </div>
            <div class="flex gap-2">
                <x-button type="submit" size="sm" icon="filter">Filter</x-button>
                @if (request()->hasAny(['search', 'account', 'status']))
                    <x-button href="{{ route('banking.transfers.index') }}" variant="ghost" size="sm">Clear</x-button>
                @endif
            </div>
        </form>

        @if ($transfers->isEmpty())
            <x-empty-state icon="arrow-right" title="No transfers" description="Move money between accounts with a transfer." />
        @else
            <div class="table-wrap !border-0 !rounded-none">
                <table class="table-base">
                    <thead>
                        <tr>
                            <th>Number</th>
                            <th>Date</th>
                            <th>From</th>
                            <th>To</th>
                            <th class="text-right">Amount</th>
                            <th>Status</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($transfers as $transfer)
                            <tr>
                                <td><span class="font-mono text-xs font-medium text-primary">{{ $transfer->number }}</span></td>
                                <td class="text-ink-soft">{{ $transfer->transfer_date?->format('Y-m-d') }}</td>
                                <td class="text-ink">{{ $transfer->fromAccount?->name }}</td>
                                <td class="text-ink">{{ $transfer->toAccount?->name }}</td>
                                <td class="text-right font-medium text-ink">{{ money($transfer->amount, $transfer->fromAccount?->currency) }}</td>
                                <td><x-banking.status-badge :status="$transfer->status" /></td>
                                <td class="text-right">
                                    @if (auth()->user()->can('banking.transfers.edit'))
                                        <div class="flex items-center justify-end gap-1">
                                            <a href="{{ route('banking.transfers.edit', $transfer) }}" class="btn-ghost btn-icon btn-sm" title="Edit">
                                                <x-icon name="edit" class="size-4" />
                                            </a>
                                            @if (auth()->user()->can('banking.transfers.delete') && ! $transfer->isCompleted())
                                                <form method="POST" action="{{ route('banking.transfers.destroy', $transfer) }}"
                                                    onsubmit="return confirm('Delete transfer {{ $transfer->number }}?');">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn-ghost btn-icon btn-sm text-rose-500" title="Delete">
                                                        <x-icon name="trash" class="size-4" />
                                                    </button>
                                                </form>
                                            @endif
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($transfers->hasPages())
            <div class="px-5 py-4">
                {{ $transfers->links() }}
            </div>
        @endif
    </x-card>
</x-app-layout>
