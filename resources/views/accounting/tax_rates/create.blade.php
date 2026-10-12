<x-app-layout :pageTitle="'New tax rate'">
    <x-slot name="header">
        <x-page-header title="New tax rate"
                       description="Rates are effective dated. A document resolves to the rate in force on its own date."
                       icon="tax" />
    </x-slot>

    <div class="max-w-2xl">
        <x-card title="Rate">
            <form method="POST" action="{{ route('accounting.tax_rates.store') }}" class="space-y-5">
                @csrf

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <x-input-label for="name" value="Name" />
                        <x-input id="name" name="name" class="mt-1 block w-full" :value="old('name')"
                                 placeholder="Standard VAT" required />
                        <x-input-error class="mt-1" :messages="$errors->get('name')" />
                    </div>
                    <div>
                        <x-input-label for="code" value="Code" />
                        <x-input id="code" name="code" class="mt-1 block w-full" :value="old('code')"
                                 placeholder="VAT-STANDARD" required />
                        <p class="mt-1 text-xs text-ink-faint">Unique. Used on documents and returns.</p>
                        <x-input-error class="mt-1" :messages="$errors->get('code')" />
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <x-input-label for="rate" value="Percentage" />
                        <x-input id="rate" name="rate" type="number" step="0.0001" min="0" max="100"
                                 class="mt-1 block w-full" :value="old('rate', '0')" required />
                        <p class="mt-1 text-xs text-ink-faint">Four decimal places, for rates such as 16.6667%.</p>
                        <x-input-error class="mt-1" :messages="$errors->get('rate')" />
                    </div>
                    <div>
                        <x-input-label for="applies_to" value="Applies to" />
                        <x-select name="applies_to" label="Applies to" class="mt-1 block w-full"
                                  :value="old('applies_to', 'sales')">
                            <option value="sales">Sales &mdash; charged to customers</option>
                            <option value="purchase">Purchase &mdash; reclaimable input tax</option>
                            <option value="both">Both</option>
                        </x-select>
                    </div>
                </div>

                <x-select name="account_id" label="Ledger account" class="mt-1 block w-full">
                    <option value="">— Not posted —</option>
                    @foreach ($accounts as $account)
                        <option value="{{ $account->id }}" @selected((int) old('account_id') === $account->id)>
                            {{ $account->code }} &mdash; {{ $account->name }}
                        </option>
                    @endforeach
                </x-select>
                <p class="-mt-3 text-xs text-ink-faint">
                    Records where tax collected under this rate is posted: Output Tax Payable
                    (2200) for sales, Input Tax Receivable (1310) for purchase. The ledger
                    posts to those accounts, so set this to match &mdash;
                    <code class="font-mono">php artisan accounting:verify-tax-rates</code>
                    checks it. Leaving it blank does not stop tax posting; it only leaves the
                    rate unverified.
                </p>

                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <x-input-label for="effective_from" value="In force from" />
                        <x-input id="effective_from" name="effective_from" type="date" class="mt-1 block w-full"
                                 :value="old('effective_from', $suggestedFrom)" required />
                    </div>
                    <div>
                        <x-input-label for="effective_to" value="Until (optional)" />
                        <x-input id="effective_to" name="effective_to" type="date" class="mt-1 block w-full"
                                 :value="old('effective_to')" />
                    </div>
                    <div>
                        <x-input-label for="priority" value="Priority" />
                        <x-input id="priority" name="priority" type="number" min="0" max="100"
                                 class="mt-1 block w-full" :value="old('priority', 0)" />
                        <p class="mt-1 text-xs text-ink-faint">Higher wins when two rates overlap.</p>
                    </div>
                </div>

                <div class="rounded-lg border border-line bg-surface-muted p-4">
                    <p class="text-xs font-medium uppercase tracking-wide text-ink-faint">Special rules</p>
                    <div class="mt-3 space-y-3">
                        <div class="flex items-center gap-3">
                            <input type="checkbox" name="is_compound" value="1" id="is_compound"
                                   @checked(old('is_compound')) class="rounded border-line">
                            <label for="is_compound" class="text-sm text-ink">
                                Charge tax on the tax as well
                            </label>
                        </div>
                        <p class="-mt-2 pl-7 text-xs text-ink-faint">
                            A 20% compound rate on a 100 net collects 25, so the rate is true of the gross.
                        </p>

                        <div>
                            <x-input-label for="threshold_amount" value="Only above this net amount" />
                            <x-input id="threshold_amount" name="threshold_amount" type="number" step="0.01" min="0"
                                     class="mt-1 block w-full" :value="old('threshold_amount')" />
                            <p class="mt-1 text-xs text-ink-faint">
                                Blank means no threshold. Small transactions are then exempt without changing the rate.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-3 border-t border-line pt-5">
                    <x-button type="submit">Create rate</x-button>
                    <x-button href="{{ route('accounting.tax_rates.index') }}" variant="ghost">Cancel</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>