<x-app-layout :pageTitle="'Edit transfer '.$transfer->number">
    <x-slot name="header">
        <x-page-header title="Transfer {{ $transfer->number }}" description="{{ $transfer->fromAccount?->name }} → {{ $transfer->toAccount?->name }}" icon="arrow-right">
            <x-slot name="actions">
                <x-button href="{{ route('banking.transfers.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-card title="Transfer details">
                <form method="POST" action="{{ route('banking.transfers.update', $transfer) }}" class="space-y-5">
                    @csrf
                    @method('PUT')

                    @if ($transfer->isCompleted())
                        <p class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-400">
                            This transfer is completed and locked. Cancel it from the status panel to reverse the posted transactions.
                        </p>
                    @endif

                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <x-select name="from_account_id" label="From account" :disabled="$transfer->isCompleted()" required>
                            @foreach ($accounts as $account)
                                <option value="{{ $account->id }}" @selected(old('from_account_id', $transfer->from_account_id) == $account->id)>{{ $account->name }} ({{ $account->currency }})</option>
                            @endforeach
                        </x-select>
                        <x-select name="to_account_id" label="To account" :disabled="$transfer->isCompleted()" required>
                            @foreach ($accounts as $account)
                                <option value="{{ $account->id }}" @selected(old('to_account_id', $transfer->to_account_id) == $account->id)>{{ $account->name }} ({{ $account->currency }})</option>
                            @endforeach
                        </x-select>
                        <x-input name="transfer_date" label="Transfer date" type="date" value="{{ old('transfer_date', $transfer->transfer_date?->format('Y-m-d')) }}" :disabled="$transfer->isCompleted()" required />
                        <x-input name="amount" label="Amount" type="number" step="0.01" min="0.01" value="{{ old('amount', $transfer->amount) }}" :disabled="$transfer->isCompleted()" required />
                    </div>

                    <x-textarea name="description" label="Description" rows="3" :disabled="$transfer->isCompleted()">{{ old('description', $transfer->description) }}</x-textarea>

                    @if (! $transfer->isCompleted())
                        <div class="flex justify-end gap-3 border-t border-line pt-4">
                            <x-button type="submit" icon="save">Save changes</x-button>
                        </div>
                    @endif
                </form>
            </x-card>
        </div>

        <div class="space-y-6">
            <x-card title="Summary">
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-ink-faint">From balance</dt><dd class="text-ink">{{ money($transfer->fromAccount?->balance() ?? 0, $transfer->fromAccount?->currency) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-faint">To balance</dt><dd class="text-ink">{{ money($transfer->toAccount?->balance() ?? 0, $transfer->toAccount?->currency) }}</dd></div>
                    <div class="flex justify-between border-t border-line pt-2"><dt class="text-ink font-semibold">Amount</dt><dd class="font-semibold text-ink">{{ money($transfer->amount, $transfer->fromAccount?->currency) }}</dd></div>
                </dl>
                <div class="mt-4">
                    <x-banking.status-badge :status="$transfer->status" />
                </div>
            </x-card>

            @if (auth()->user()->can('banking.transfers.edit'))
                <x-card title="Status">
                    <form method="POST" action="{{ route('banking.transfers.status', $transfer) }}" class="space-y-3">
                        @csrf
                        @method('PATCH')
                        <x-select name="status" label="Move to">
                            @foreach (\App\Models\BankTransfer::statusOptions() as $status)
                                <option value="{{ $status }}" @selected($transfer->status === $status)>{{ ucfirst($status) }}</option>
                            @endforeach
                        </x-select>
                        <x-button type="submit" icon="save" class="w-full">Update status</x-button>
                    </form>
                </x-card>
            @endif

            @if ($transfer->transactions->isNotEmpty())
                <x-card title="Posted transactions" :padding="false">
                    <div class="divide-y divide-line">
                        @foreach ($transfer->transactions as $transaction)
                            <div class="flex items-center justify-between gap-3 px-5 py-3">
                                <div>
                                    <p class="text-sm font-medium text-ink">{{ $transaction->account?->name }}</p>
                                    <p class="text-xs text-ink-faint">{{ $transaction->number }} · {{ ucfirst(str_replace('_', ' ', $transaction->type)) }}</p>
                                </div>
                                <span class="text-sm font-medium {{ $transaction->isDebit() ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400' }}">
                                    {{ $transaction->isDebit() ? '−' : '+' }}{{ money($transaction->amount, $transaction->account?->currency) }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                </x-card>
            @endif
        </div>
    </div>
</x-app-layout>
