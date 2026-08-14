<x-app-layout :pageTitle="'New journal entry'">
    <x-slot name="header">
        <x-page-header title="New journal entry" description="Record a dated double-entry journal. Debits must equal credits to post." icon="document" />
    </x-slot>

    @include('accounting._tabs')

    @php
        $accountsJs = $accounts->map(fn ($a) => ['id' => $a->id, 'label' => $a->code.' â€” '.$a->name])->values();
    @endphp

    <div class="mt-6 max-w-4xl">
        <x-card :padding="false">
            <form method="POST" action="{{ route('accounting.journal.store') }}" class="space-y-5 p-5"
                x-data="journalForm(@js($accountsJs))">
                @csrf

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <x-input name="entry_date" label="Entry date" type="date" value="{{ old('entry_date', now()->toDateString()) }}" required />
                    <x-input name="reference" label="Reference" placeholder="Optional reference" value="{{ old('reference') }}" />
                    <x-input name="description" label="Description" placeholder="What is this entry for?" value="{{ old('description') }}" />
                </div>

                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-ink">Lines</h3>
                    <x-button type="button" size="sm" variant="secondary" icon="plus" @click="addLine()">Add line</x-button>
                </div>

                <div class="table-wrap">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th class="w-64">Account</th>
                                <th class="w-40">Debit</th>
                                <th class="w-40">Credit</th>
                                <th>Memo</th>
                                <th class="w-10"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(line, index) in lines" :key="index">
                                <tr>
                                    <td>
                                        <select :name="`lines[${index}][account_id]`" x-model.number="line.account_id" required class="input w-full">
                                            <option value="">Select account</option>
                                            <template x-for="acc in accounts" :key="acc.id">
                                                <option :value="acc.id" x-text="acc.label"></option>
                                            </template>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="number" step="0.01" min="0" :name="`lines[${index}][debit]`" x-model="line.debit"
                                            class="input w-full text-right" placeholder="0.00" @input.debounce="0" />
                                    </td>
                                    <td>
                                        <input type="number" step="0.01" min="0" :name="`lines[${index}][credit]`" x-model="line.credit"
                                            class="input w-full text-right" placeholder="0.00" @input.debounce="0" />
                                    </td>
                                    <td>
                                        <input type="text" :name="`lines[${index}][memo]`" x-model="line.memo" class="input w-full" placeholder="Optional" />
                                    </td>
                                    <td>
                                        <button type="button" class="btn-ghost btn-icon btn-sm text-rose-500" @click="lines.splice(index, 1)" x-show="lines.length > 1" title="Remove line">
                                            <x-icon name="trash" class="size-4" />
                                        </button>
                                    </td>
                                </tr>
                            </template>
                            <tr x-show="lines.length === 0">
                                <td colspan="5" class="py-8 text-center text-sm text-ink-faint">Add at least two lines to balance the entry.</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td class="text-right font-semibold text-ink">Totals</td>
                                <td class="text-right font-semibold text-ink" x-text="money(totalDebit())"></td>
                                <td class="text-right font-semibold text-ink" x-text="money(totalCredit())"></td>
                                <td colspan="2">
                                    <span class="text-xs font-medium" x-text="balanceHint()" :class="balanced ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'"></span>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="flex items-center gap-2 pt-1">
                    <x-button type="submit" icon="save">Save entry</x-button>
                    <x-button href="{{ route('accounting.journal.index') }}" variant="secondary">Cancel</x-button>
                </div>
            </form>
        </x-card>
    </div>

    <script>
        function journalForm(accountOptions, initialLines = []) {
            return {
                accounts: accountOptions,
                lines: initialLines.length ? initialLines : [
                    { account_id: '', debit: '', credit: '', memo: '' },
                    { account_id: '', debit: '', credit: '', memo: '' },
                ],
                addLine() {
                    this.lines.push({ account_id: '', debit: '', credit: '', memo: '' });
                },
                totalDebit() {
                    return this.lines.reduce((s, l) => s + (parseFloat(l.debit) || 0), 0);
                },
                totalCredit() {
                    return this.lines.reduce((s, l) => s + (parseFloat(l.credit) || 0), 0);
                },
                get balanced() {
                    const d = Math.round(this.totalDebit() * 100);
                    const c = Math.round(this.totalCredit() * 100);
                    return d > 0 && d === c;
                },
                balanceHint() {
                    const d = Math.round(this.totalDebit() * 100);
                    const c = Math.round(this.totalCredit() * 100);
                    if (d === 0 && c === 0) return 'Add lines to balance';
                    return d === c ? 'Balanced' : 'Difference: ' + this.money(d / 100 - c / 100);
                },
                money(v) {
                    return new Intl.NumberFormat('en', { style: 'currency', currency: '{{ settings('company.currency', 'USD') }}' }).format(v || 0);
                },
            };
        }
    </script>
</x-app-layout>