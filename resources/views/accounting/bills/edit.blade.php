<x-app-layout :pageTitle="'Edit '.$bill->number">
    <x-slot name="header">
        <x-page-header title="Edit bill" :description="$bill->number" icon="edit">
            <x-slot name="actions">
                <x-button href="{{ route('accounting.bills.show', $bill) }}" variant="ghost" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    @include('accounting._tabs')

    @php
        $accountsJs = $accounts->map(fn ($a) => ['id' => $a->id, 'label' => $a->code.' — '.$a->name])->values();
        $initialLines = $bill->items->map(fn ($i) => [
            'account_id' => (string) $i->account_id,
            'description' => $i->description ?? '',
            'amount' => (string) $i->amount,
        ])->values();
    @endphp

    <div class="mt-6 max-w-4xl">
        <x-card :padding="false">
            <form method="POST" action="{{ route('accounting.bills.update', $bill) }}" class="space-y-5 p-5" x-data="billLines(@js($accountsJs), @js($initialLines))">
                @csrf
                @method('PUT')

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-input name="vendor_name" label="Vendor" required value="{{ old('vendor_name', $bill->vendor_name) }}" />
                    <div>
                        <x-select name="supplier_id" label="Supplier">
                            <option value="">None</option>
                            @foreach ($suppliers as $supplier)
                                <option value="{{ $supplier->id }}" @selected(old('supplier_id', $bill->supplier_id) == $supplier->id)>{{ $supplier->company_name }}</option>
                            @endforeach
                        </x-select>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <x-input name="bill_date" label="Bill date" type="date" required value="{{ old('bill_date', $bill->bill_date->format('Y-m-d')) }}" />
                    <x-input name="due_date" label="Due date" type="date" value="{{ old('due_date', $bill->due_date?->format('Y-m-d')) }}" />
                    <x-input name="reference" label="Reference" value="{{ old('reference', $bill->reference) }}" />
                </div>

                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-ink">Line items</h3>
                    <x-button type="button" size="sm" variant="secondary" icon="plus" @click="addLine()">Add line</x-button>
                </div>

                <div class="table-wrap">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th class="w-64">Account</th>
                                <th>Description</th>
                                <th class="w-40">Amount</th>
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
                                        <input type="text" :name="`lines[${index}][description]`" x-model="line.description" class="input w-full" />
                                    </td>
                                    <td>
                                        <input type="number" step="0.01" min="0" :name="`lines[${index}][amount]`" x-model="line.amount"
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
                                <td colspan="2" class="text-right font-semibold text-ink">Total</td>
                                <td class="text-right font-semibold text-ink" x-text="money(total())"></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-input name="currency" label="Currency" required value="{{ old('currency', $bill->currency) }}" />
                </div>
                <x-textarea name="notes" label="Notes" value="{{ old('notes', $bill->notes) }}" />

                <div class="flex items-center gap-2 pt-1">
                    <x-button type="submit" icon="save">Save changes</x-button>
                    <x-button href="{{ route('accounting.bills.show', $bill) }}" variant="secondary">Cancel</x-button>
                </div>
            </form>
        </x-card>
    </div>

    <script>
        function billLines(accountOptions, initialLines = []) {
            return {
                accounts: accountOptions,
                lines: initialLines,
                addLine() {
                    this.lines.push({ account_id: '', description: '', amount: '' });
                },
                total() {
                    return this.lines.reduce((s, l) => s + (parseFloat(l.amount) || 0), 0);
                },
                money(v) {
                    return new Intl.NumberFormat('en', { style: 'currency', currency: '{{ settings('company.currency', 'USD') }}' }).format(v || 0);
                },
            };
        }
    </script>
</x-app-layout>