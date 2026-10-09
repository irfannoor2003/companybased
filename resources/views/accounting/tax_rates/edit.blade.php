<x-app-layout :pageTitle="'Supersede '.$taxRate->code">
    <x-slot name="header">
        <x-page-header :title="'Supersede '.$taxRate->code"
                       description="The current rate is closed the day before the replacement opens. Documents already issued are untouched."
                       icon="tax" />
    </x-slot>

    <x-toasts />

    <div class="grid gap-6 lg:grid-cols-2">
        <div>
            <x-card :title="'Current rate: '.$taxRate->label()">
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-ink-faint">Code</dt>
                        <dd class="font-mono text-xs text-ink">{{ $taxRate->code }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-ink-faint">In force</dt>
                        <dd class="text-ink">
                            {{ $taxRate->effective_from->format('j M Y') }} &ndash;
                            {{ $taxRate->effective_to?->format('j M Y') ?? 'open' }}
                        </dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-ink-faint">Applies to</dt>
                        <dd class="text-ink">{{ ucfirst($taxRate->applies_to) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-ink-faint">Posts to</dt>
                        <dd class="text-ink">{{ $taxRate->account?->code ?? '—' }}</dd>
                    </div>
                </dl>

                <x-alert type="info" class="mt-4">
                    This rate is not edited. It is closed the day before your replacement
                    opens, so every document already issued keeps the rate it was raised
                    under.
                </x-alert>
            </x-card>
        </div>

        <div>
            <x-card title="Replacement rate">
                <form method="POST" action="{{ route('accounting.tax_rates.update', $taxRate) }}" class="space-y-5">
                    @csrf
                    @method('PUT')

                    <div>
                        <x-input-label for="name" value="Name" />
                        <x-input id="name" name="name" class="mt-1 block w-full"
                                 :value="old('name', $taxRate->name.' '.now()->year)" required />
                        <x-input-error class="mt-1" :messages="$errors->get('name')" />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <x-input-label for="rate" value="Percentage" />
                            <x-input id="rate" name="rate" type="number" step="0.0001" min="0" max="100"
                                     class="mt-1 block w-full" :value="old('rate', $taxRate->rate)" required />
                        </div>
                        <div>
                            <x-input-label for="applies_to" value="Applies to" />
                            <x-select name="applies_to" label="Applies to" class="mt-1 block w-full"
                                      :value="old('applies_to', $taxRate->applies_to)">
                                <option value="sales">Sales</option>
                                <option value="purchase">Purchase</option>
                                <option value="both">Both</option>
                            </x-select>
                        </div>
                    </div>

                    <x-select name="account_id" label="Ledger account" class="mt-1 block w-full">
                        <option value="">— Not posted —</option>
                        @foreach ($accounts as $account)
                            <option value="{{ $account->id }}" @selected((int) old('account_id', $taxRate->account_id) === $account->id)>
                                {{ $account->code }} &mdash; {{ $account->name }}
                            </option>
                        @endforeach
                    </x-select>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <x-input-label for="effective_from" value="Takes effect from" />
                            <x-input id="effective_from" name="effective_from" type="date"
                                     class="mt-1 block w-full" :value="old('effective_from', today()->toDateString())" required />
                            <p class="mt-1 text-xs text-ink-faint">
                                Today or later. The current rate closes the day before.
                            </p>
                        </div>
                        <div>
                            <x-input-label for="priority" value="Priority" />
                            <x-input id="priority" name="priority" type="number" min="0" max="100"
                                     class="mt-1 block w-full" :value="old('priority', $taxRate->priority)" />
                        </div>
                    </div>

                    <div class="space-y-3">
                        <div class="flex items-center gap-3">
                            <input type="checkbox" name="is_compound" value="1" id="is_compound"
                                   @checked(old('is_compound', $taxRate->is_compound)) class="rounded border-line">
                            <label for="is_compound" class="text-sm text-ink">Charge tax on the tax as well</label>
                        </div>

                        <div>
                            <x-input-label for="threshold_amount" value="Only above this net amount" />
                            <x-input id="threshold_amount" name="threshold_amount" type="number" step="0.01" min="0"
                                     class="mt-1 block w-full" :value="old('threshold_amount', $taxRate->threshold_amount)" />
                        </div>
                    </div>

                    <div class="flex items-center gap-3 border-t border-line pt-5">
                        <x-button type="submit">Create replacement</x-button>
                        <x-button href="{{ route('accounting.tax_rates.index') }}" variant="ghost">Cancel</x-button>
                    </div>
                </form>
            </x-card>
        </div>
    </div>
</x-app-layout>