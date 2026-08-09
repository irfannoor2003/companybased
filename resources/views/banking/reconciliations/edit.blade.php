<x-app-layout :pageTitle="'Edit reconciliation '.$reconciliation->number">
    <x-slot name="header">
        <x-page-header title="Reconciliation {{ $reconciliation->number }}" description="{{ $reconciliation->account?->name }}" icon="check-circle">
            <x-slot name="actions">
                <x-button href="{{ route('banking.reconciliations.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    @php
        $bookBalance = (float) $reconciliation->opening_balance
            + $transactions->where('is_cleared')->sum(fn ($t) => $t->signedAmount());
        $difference = round((float) $reconciliation->statement_ending_balance - $bookBalance, 2);
    @endphp

    <div
        class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3"
        x-data="{
            opening: {{ (float) $reconciliation->opening_balance }},
            statementEnding: {{ (float) $reconciliation->statement_ending_balance }},
            signed: {{ \Illuminate\Support\Js::from($transactions->mapWithKeys(fn ($t) => [$t->id => $t->signedAmount()])->all()) }},
            clearedSum() {
                return Object.entries(this.signed).reduce((sum, [id, v]) => {
                    const el = document.querySelector('input[name=&quot;cleared[' + id + ']&quot;]');
                    return sum + (el && el.checked ? v : 0);
                }, 0);
            },
            book() { return this.opening + this.clearedSum(); },
            diff() { return this.statementEnding - this.book(); },
            money(v) {
                return new Intl.NumberFormat('en', { style: 'currency', currency: {{ \Illuminate\Support\Js::from($reconciliation->account?->currency ?? 'USD') }} }).format(v);
            },
        }"
    >
        <div class="lg:col-span-2">
            <x-card title="Statement details">
                <form method="POST" action="{{ route('banking.reconciliations.update', $reconciliation) }}" class="space-y-5">
                    @csrf
                    @method('PUT')

                    @if ($reconciliation->isCompleted())
                        <p class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-400">
                            This reconciliation is completed and locked. Cancel it from the status panel to re-open the flagged transactions.
                        </p>
                    @endif

                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <x-select name="bank_account_id" label="Account" :disabled="$reconciliation->isCompleted()" required>
                            @foreach ($accounts as $account)
                                <option value="{{ $account->id }}" @selected(old('bank_account_id', $reconciliation->bank_account_id) == $account->id)>{{ $account->name }} ({{ $account->currency }})</option>
                            @endforeach
                        </x-select>
                        <x-input name="statement_date" label="Statement date" type="date" value="{{ old('statement_date', $reconciliation->statement_date?->format('Y-m-d')) }}" :disabled="$reconciliation->isCompleted()" required />
                        <x-input name="statement_ending_balance" label="Statement ending balance" type="number" step="0.01" value="{{ old('statement_ending_balance', $reconciliation->statement_ending_balance) }}" :disabled="$reconciliation->isCompleted()" required />
                    </div>

                    <x-textarea name="notes" label="Notes" rows="2" :disabled="$reconciliation->isCompleted()">{{ old('notes', $reconciliation->notes) }}</x-textarea>

                    @if ($transactions->isEmpty())
                        <x-empty-state icon="money" title="No transactions in scope" description="There are no book transactions up to this statement date." />
                    @else
                        <div>
                            <p class="mb-2 text-sm font-semibold text-ink">Statement lines</p>
                            <div class="table-wrap !border-0 !rounded-none !shadow-none">
                                <table class="table-base">
                                    <thead>
                                        <tr>
                                            <th class="w-10">Cleared</th>
                                            <th>Date</th>
                                            <th>Type</th>
                                            <th>Description</th>
                                            <th class="text-right">Amount</th>
                                            <th class="text-right">Running balance</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($transactions as $transaction)
                                            <tr>
                                                <td>
                                                    <input type="checkbox"
                                                        name="cleared[{{ $transaction->id }}]"
                                                        value="1"
                                                        @checked($transaction->is_cleared)
                                                        @disabled($reconciliation->isCompleted())
                                                        class="size-4 rounded border-line accent-primary"
                                                        x-on:change="clearedSum()">
                                                </td>
                                                <td class="text-ink-soft">{{ $transaction->transaction_date?->format('Y-m-d') }}</td>
                                                <td><span class="text-xs text-ink-soft">{{ ucfirst(str_replace('_', ' ', $transaction->type)) }}</span></td>
                                                <td class="text-ink">{{ $transaction->description ?? $transaction->counterparty ?? $transaction->number }}</td>
                                                <td class="text-right font-medium {{ $transaction->isDebit() ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400' }}">
                                                    {{ $transaction->isDebit() ? '−' : '+' }}{{ money($transaction->amount, $reconciliation->account?->currency) }}
                                                </td>
                                                <td class="text-right text-ink-soft">{{ money($transaction->running_balance, $reconciliation->account?->currency) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif

                    @if (! $reconciliation->isCompleted())
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
                    <div class="flex justify-between"><dt class="text-ink-faint">Opening balance</dt><dd class="text-ink">{{ money($reconciliation->opening_balance, $reconciliation->account?->currency) }}</dd></div>
                    <div class="flex justify-between border-t border-line pt-2"><dt class="text-ink font-semibold">Book balance</dt><dd class="font-semibold text-ink" x-text="money(book())">{{ money($bookBalance, $reconciliation->account?->currency) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-faint">Statement ending</dt><dd class="text-ink" x-text="money(statementEnding)">{{ money($reconciliation->statement_ending_balance, $reconciliation->account?->currency) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-faint">Difference</dt>
                        <dd class="font-medium" x-bind:class="diff() === 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'" x-text="money(diff())">
                            <span class="{{ $difference == 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' }}">{{ money($difference, $reconciliation->account?->currency) }}</span>
                        </dd>
                    </div>
                </dl>
                <div class="mt-4">
                    <x-banking.status-badge :status="$reconciliation->status" />
                </div>
            </x-card>

            @if (auth()->user()->can('banking.reconciliations.edit'))
                <x-card title="Status">
                    <form method="POST" action="{{ route('banking.reconciliations.status', $reconciliation) }}" class="space-y-3">
                        @csrf
                        @method('PATCH')
                        <x-select name="status" label="Move to">
                            @foreach (\App\Models\Reconciliation::statusOptions() as $status)
                                <option value="{{ $status }}" @selected($reconciliation->status === $status)>{{ ucfirst($status) }}</option>
                            @endforeach
                        </x-select>
                        <x-button type="submit" icon="save" class="w-full">Update status</x-button>
                    </form>
                </x-card>
            @endif
        </div>
    </div>
</x-app-layout>
