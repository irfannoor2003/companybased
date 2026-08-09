<x-app-layout :pageTitle="'New budget'">
    <x-slot name="header">
        <x-page-header title="New budget" description="Set annual targets against revenue and expense accounts." icon="plus" />
    </x-slot>

    @include('accounting._tabs')

    @php
        $accountsJs = $accounts->map(fn ($a) => ['id' => $a->id, 'label' => $a->code.' — '.$a->name])->values();
    @endphp

    <div class="mt-6 max-w-4xl">
        <x-card :padding="false">
            <form method="POST" action="{{ route('accounting.budgets.store') }}" class="space-y-5 p-5" x-data="budgetLines(@js($accountsJs))">
                @csrf

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <x-input name="name" label="Budget name" required placeholder="e.g. FY2026 Operating" value="{{ old('name') }}" />
                    <x-input name="fiscal_year" label="Fiscal year" required placeholder="2026" value="{{ old('fiscal_year', now()->format('Y')) }}" />
                    <x-input name="currency" label="Currency" required value="{{ old('currency', settings('company.currency', 'USD')) }}" />
                </div>

                <div>
                    <x-select name="status" label="Status" required>
                        @foreach (\App\Models\Budget::statusOptions() as $status)
                            <option value="{{ $status }}" @selected(old('status') === $status)>{{ ucfirst($status) }}</option>
                        @endforeach
                    </x-select>
                </div>

                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-ink">Accounts</h3>
                    <x-button type="button" size="sm" variant="secondary" icon="plus" @click="addLine()">Add line</x-button>
                </div>

                <div class="table-wrap">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th class="w-96">Account</th>
                                <th class="w-48">Annual budget</th>
                                <th class="w-10"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(line, index) in lines" :key="index">
                                <tr>
                                    <td>
                                        <select :name="`lines[${index}][account_id]`" x-model="line.account_id" required class="select-input w-full">
                                            <option value="">Select account</option>
                                            <template x-for="acc in accounts" :key="acc.id">
                                                <option :value="acc.id" x-text="acc.label"></option>
                                            </template>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="number" step="0.01" min="0" :name="`lines[${index}][budget_amount]`" x-model="line.budget_amount"
                                            class="input w-full text-right" placeholder="0.00" required />
                                    </td>
                                    <td>
                                        <button type="button" class="btn-ghost btn-icon btn-sm text-rose-500" @click="lines.splice(index, 1)" x-show="lines.length > 1" title="Remove line">
                                            <x-icon name="trash" class="size-4" />
                                        </button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td class="text-right font-semibold text-ink">Total budgeted</td>
                                <td class="text-right font-semibold text-ink" x-text="money(total())"></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <x-textarea name="description" label="Description" value="{{ old('description') }}" />

                <div class="flex items-center gap-2 pt-1">
                    <x-button type="submit" icon="save">Create budget</x-button>
                    <x-button href="{{ route('accounting.budgets.index') }}" variant="secondary">Cancel</x-button>
                </div>
            </form>
        </x-card>
    </div>

    <script>
        function budgetLines(accountOptions, initialLines = []) {
            return {
                accounts: accountOptions,
                lines: initialLines,
                addLine() {
                    this.lines.push({ account_id: '', budget_amount: '' });
                },
                total() {
                    return this.lines.reduce((s, l) => s + (parseFloat(l.budget_amount) || 0), 0);
                },
                money(v) {
                    return new Intl.NumberFormat('en', { style: 'currency', currency: 'USD' }).format(v || 0);
                },
            };
        }
    </script>
</x-app-layout>