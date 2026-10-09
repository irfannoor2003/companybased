<x-app-layout :pageTitle="'New fiscal period'">
    <x-slot name="header">
        <x-page-header title="New fiscal period"
                       description="Periods must not overlap. A period ending 31 March and one starting 1 April is fine."
                       icon="calendar" />
    </x-slot>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-card title="Period">
                <form method="POST" action="{{ route('accounting.fiscal_periods.store') }}" class="space-y-5">
                    @csrf

                    <div>
                        <x-input-label for="name" value="Name" />
                        <x-input id="name" name="name" type="text" class="mt-1 block w-full"
                                 :value="old('name')" placeholder="Left blank, derived from the dates" />
                        <p class="mt-1 text-xs text-ink-faint">For example "March 2026".</p>
                        <x-input-error class="mt-1" :messages="$errors->get('name')" />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <x-input-label for="start_date" value="Start date" />
                            <x-input id="start_date" name="start_date" type="date" class="mt-1 block w-full"
                                     :value="old('start_date')" required />
                            <x-input-error class="mt-1" :messages="$errors->get('start_date')" />
                        </div>

                        <div>
                            <x-input-label for="end_date" value="End date" />
                            <x-input id="end_date" name="end_date" type="date" class="mt-1 block w-full"
                                     :value="old('end_date')" required />
                            <x-input-error class="mt-1" :messages="$errors->get('end_date')" />
                        </div>
                    </div>

                    <div>
                        <x-input-label for="date_lock" value="Refuse entries dated on or before" />
                        <x-input id="date_lock" name="date_lock" type="date" class="mt-1 block w-full"
                                 :value="old('date_lock')" />
                        <p class="mt-1 text-xs text-ink-faint">
                            Optional. Blocks backdating into this period while leaving it open for new
                            work. Leave blank for no restriction.
                        </p>
                        <x-input-error class="mt-1" :messages="$errors->get('date_lock')" />
                    </div>

                    <div class="flex items-center gap-3 border-t border-line pt-5">
                        <x-button type="submit">Create period</x-button>
                        <x-button href="{{ route('accounting.fiscal_periods.index') }}" variant="ghost">Cancel</x-button>
                    </div>
                </form>
            </x-card>
        </div>

        <div>
            <x-card title="Need a whole year?"
                   description="Creating periods one at a time is how gaps appear, and a gap silently stops enforcing the period at that date.">
                <form method="POST" action="{{ route('accounting.fiscal_periods.generate') }}" class="space-y-4">
                    @csrf

                    <div>
                        <x-input-label for="after" value="Starting after" />
                        <x-input id="after" name="after" type="date" class="mt-1 block w-full" required />
                        <p class="mt-1 text-xs text-ink-faint">Months are created from the month after this date.</p>
                    </div>

                    <div>
                        <x-input-label for="months" value="Months" />
                        <x-input id="months" name="months" type="number" min="1" max="24"
                                 value="12" class="mt-1 block w-full" required />
                    </div>

                    <x-button type="submit" variant="secondary" icon="repeat">Generate monthly periods</x-button>
                </form>
            </x-card>
        </div>
    </div>
</x-app-layout>
