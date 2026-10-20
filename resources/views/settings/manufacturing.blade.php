<x-settings-layout page-title="Manufacturing">
    <div class="mt-6 space-y-6">
        <x-card
            title="Overhead absorption basis"
            description="How manufacturing overhead is charged into the cost of the goods you build. This decides what a finished item carries on the balance sheet, so it changes every margin you report."
        >
            @if ($activeCentres === 0)
                <div class="mb-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-400">
                    No work centres exist yet, so the work-centre-hour basis has nothing to
                    measure against and will absorb nothing. Create one in
                    <a href="{{ route('inventory.work_centres.create') }}" class="font-medium underline">Inventory &rarr; Work Centres</a>
                    before selecting it.
                </div>
            @elseif ($ratedCentres === 0)
                <div class="mb-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-400">
                    None of your {{ $activeCentres }} active work centre{{ $activeCentres === 1 ? ' has' : 's have' }}
                    an hourly rate. The work-centre-hour basis prices overhead from those rates,
                    so it would absorb nothing until at least one is set.
                </div>
            @endif

            <form method="POST" action="{{ route('settings.manufacturing.update') }}" class="space-y-6">
                @csrf
                @method('PUT')

                <fieldset class="space-y-3">
                    <legend class="mb-3 text-sm font-medium text-ink">Absorb overhead on</legend>

                    @php
                        $basisHelp = [
                            'labour_hour' => 'One blended rate applied to the direct labour hours recorded against each order. Simple, and right for a shop that bills by the hour. It misprices capital equipment, because a job that consumed three machine hours and twenty minutes of attention is charged as though all of it were attention.',
                            'work_centre_hour' => 'Each work centre charges its own hourly rate for the minutes the job consumed from it — the greater of the standard time in the routing and the time actually recorded. Right for a shop running its own machinery.',
                        ];
                    @endphp

                    <div class="space-y-3">
                        @foreach ($bases as $value => $label)
                            <label class="flex cursor-pointer gap-3 rounded-xl border p-4 transition
                                          {{ old('absorption_basis', $basis) === $value
                                              ? 'border-primary bg-primary/5'
                                              : 'border-line hover:bg-surface-muted' }}">
                                <input type="radio" name="absorption_basis" value="{{ $value }}"
                                       class="mt-0.5"
                                       @checked(old('absorption_basis', $basis) === $value)>
                                <span>
                                    <span class="block text-sm font-medium text-ink">{{ $label }}</span>
                                    <span class="mt-1 block text-xs text-ink-soft">{{ $basisHelp[$value] ?? '' }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-input
                        name="overhead_rate_per_hour"
                        label="Overhead rate per direct labour hour"
                        type="number"
                        step="0.0001"
                        min="0"
                        value="{{ old('overhead_rate_per_hour', $overheadRate) }}"
                        hint="Recovery rate, not a bill. Zero means overhead is not absorbed at all, which is deliberate — absorbing a rate nobody configured would invent factory cost and put it into inventory."
                    />

                    <x-input
                        name="standard_monthly_hours"
                        label="Standard monthly hours"
                        type="number"
                        step="0.5"
                        min="1"
                        max="744"
                        value="{{ old('standard_monthly_hours', $monthlyHours) }}"
                        hint="Divisor turning a monthly salary into an hourly rate. 208 is 26 days × 8 hours; a six-day week needs it raised or every job is under-absorbed."
                    />
                </div>

                <div class="flex justify-end gap-3 border-t border-line pt-4">
                    <x-button type="submit" icon="save">Save manufacturing settings</x-button>
                </div>
            </form>
        </x-card>

        <x-card
            title="What absorption is doing"
            description="The accounting side, in plain terms."
        >
            <ul class="space-y-3 text-sm text-ink-soft">
                <li class="flex gap-3">
                    <x-icon name="check" class="mt-0.5 size-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
                    <span>
                        Factory time is <strong class="text-ink">moved out of period expense and into work in
                        progress</strong>, then transferred into the finished goods when the order completes.
                        Total cost does not change — only where it sits on the balance sheet.
                    </span>
                </li>
                <li class="flex gap-3">
                    <x-icon name="check" class="mt-0.5 size-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
                    <span>
                        Nothing is charged to cost of goods sold at absorption. Nothing has been sold yet —
                        cost of goods sold follows the sale, as everywhere else.
                    </span>
                </li>
                <li class="flex gap-3">
                    <x-icon name="check" class="mt-0.5 size-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
                    <span>
                        The difference between the fully absorbed cost and the item's standard cost price
                        lands on <strong class="text-ink">Production Variance</strong>, so a routing or a
                        rate that is wrong shows up as a variance rather than being blended away.
                    </span>
                </li>
                <li class="flex gap-3">
                    <x-icon name="warning" class="mt-0.5 size-4 shrink-0 text-amber-600 dark:text-amber-400" />
                    <span>
                        Changing the basis applies to orders absorbed from now on. Work already absorbed
                        keeps the basis it was absorbed on, so a completed job's margin does not silently
                        move after the fact.
                    </span>
                </li>
            </ul>
        </x-card>
    </div>
</x-settings-layout>
