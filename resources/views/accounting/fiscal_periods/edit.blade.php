<x-app-layout :pageTitle="'Edit '.$fiscalPeriod->label()">
    <x-slot name="header">
        <x-page-header :title="'Edit '.$fiscalPeriod->label()"
                       description="A locked period cannot be changed at all."
                       icon="calendar" />
    </x-slot>

    <x-toasts />

    @if ($fiscalPeriod->isLocked())
        <x-alert type="danger" title="This period is locked">
            Its figures have been reported and can only be corrected by a reversing entry
            in an open period.
        </x-alert>
    @endif

    <div class="max-w-2xl">
        <x-card title="Period">
            <form method="POST" action="{{ route('accounting.fiscal_periods.update', $fiscalPeriod) }}"
                  class="space-y-5">
                @csrf
                @method('PUT')

                <div>
                    <x-input-label for="name" value="Name" />
                    <x-input id="name" name="name" type="text" class="mt-1 block w-full"
                             :value="old('name', $fiscalPeriod->name)"
                             :disabled="$fiscalPeriod->isLocked()" />
                    <x-input-error class="mt-1" :messages="$errors->get('name')" />
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <x-input-label for="start_date" value="Start date" />
                        <x-input id="start_date" name="start_date" type="date" class="mt-1 block w-full"
                                 :value="old('start_date', $fiscalPeriod->start_date->toDateString())"
                                 :disabled="$fiscalPeriod->isLocked()" required />
                        <x-input-error class="mt-1" :messages="$errors->get('start_date')" />
                    </div>

                    <div>
                        <x-input-label for="end_date" value="End date" />
                        <x-input id="end_date" name="end_date" type="date" class="mt-1 block w-full"
                                 :value="old('end_date', $fiscalPeriod->end_date->toDateString())"
                                 :disabled="$fiscalPeriod->isLocked()" required />
                        <x-input-error class="mt-1" :messages="$errors->get('end_date')" />
                    </div>
                </div>

                <div>
                    <x-input-label for="date_lock" value="Refuse entries dated on or before" />
                    <x-input id="date_lock" name="date_lock" type="date" class="mt-1 block w-full"
                             :value="old('date_lock', $fiscalPeriod->date_lock?->toDateString())"
                             :disabled="$fiscalPeriod->isLocked()" />
                    <p class="mt-1 text-xs text-ink-faint">
                        Clearing this lifts the backdating restriction without closing the period.
                    </p>
                    <x-input-error class="mt-1" :messages="$errors->get('date_lock')" />
                </div>

                <div class="flex items-center gap-3 border-t border-line pt-5">
                    <x-button type="submit" :disabled="$fiscalPeriod->isLocked()">Save changes</x-button>
                    <x-button href="{{ route('accounting.fiscal_periods.index') }}" variant="ghost">Back</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
