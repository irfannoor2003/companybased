<x-app-layout :pageTitle="'Attendance rules'">
    <x-slot name="header">
        <x-page-header title="Attendance rules" description="Shift schedule, grace periods and salary deductions applied to attendance." icon="settings">
            <x-slot name="actions">
                <x-button href="{{ route('employees.attendance.index') }}" variant="ghost" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    @include('employees._tabs')

    <div class="mt-6 max-w-4xl">
        <x-card>
            <form method="POST" action="{{ route('employees.attendance.rules.update') }}" class="space-y-6">
                @csrf
                @method('PUT')

                <div>
                    <h3 class="text-sm font-semibold text-ink">Shift schedule</h3>
                    <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <x-input name="shift_start" label="Shift start" type="time" value="{{ old('shift_start', $rules['shift_start']) }}" :error="$errors->first('shift_start')" />
                        <x-input name="shift_end" label="Shift end" type="time" value="{{ old('shift_end', $rules['shift_end']) }}" :error="$errors->first('shift_end')" />
                        <x-input name="grace_minutes" label="Grace period (minutes)" type="number" min="0" value="{{ old('grace_minutes', $rules['grace_minutes']) }}" :error="$errors->first('grace_minutes')" />
                    </div>
                    <p class="mt-2 text-xs text-ink-faint">Check-in within the grace period counts as present; the grace period also applies to clock-out.</p>
                </div>

                <div>
                    <h3 class="text-sm font-semibold text-ink">Thresholds</h3>
                    <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-input name="short_leave_threshold_minutes" label="Short-leave threshold (minutes)" type="number" min="0" hint="Leaving this many minutes before shift end = short leave." value="{{ old('short_leave_threshold_minutes', $rules['short_leave_threshold_minutes']) }}" :error="$errors->first('short_leave_threshold_minutes')" />
                        <x-input name="half_day_cutoff_minutes" label="Half-day cutoff (minutes)" type="number" min="0" hint="Check-in at/after shift start + this = half day." value="{{ old('half_day_cutoff_minutes', $rules['half_day_cutoff_minutes']) }}" :error="$errors->first('half_day_cutoff_minutes')" />
                    </div>
                </div>

                <div>
                    <h3 class="text-sm font-semibold text-ink">Weekends</h3>
                    <p class="mt-1 text-xs text-ink-faint">Days that are not working days. No check-in is required and they are excluded from pay periods.</p>
                    <div class="mt-4 flex flex-wrap gap-4">
                        @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $day)
                            @php $checked = in_array($day, old('weekend_days', $rules['weekend_days']), true); @endphp
                            <label class="flex cursor-pointer items-center gap-2">
                                <input type="checkbox" name="weekend_days[]" value="{{ $day }}" {{ $checked ? 'checked' : '' }} class="size-4 rounded border-line text-primary focus:ring-primary" />
                                <span class="text-sm text-ink">{{ $day }}</span>
                            </label>
                        @endforeach
                    </div>
                    @if ($errors->first('weekend_days'))
                        <p class="mt-1.5 text-xs text-rose-600 dark:text-rose-400">{{ $errors->first('weekend_days') }}</p>
                    @endif
                </div>

                <div>
                    <h3 class="text-sm font-semibold text-ink">Deductions</h3>
                    <p class="mt-1 text-xs text-ink-faint">Flat amounts are per occurrence; percent is a share of the employee's daily rate (gross ÷ working days).</p>

                    @foreach ([
                        'late' => 'Late',
                        'short_leave' => 'Short leave',
                        'absent' => 'Absent',
                    ] as $key => $label)
                        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <x-select name="deduction_{{ $key }}_type" :label="$label.' deduction type'" size="sm">
                                <option value="flat" @selected(old('deduction_'.$key.'_type', $rules['deduction_'.$key.'_type']) === 'flat')>Flat amount</option>
                                <option value="percent" @selected(old('deduction_'.$key.'_type', $rules['deduction_'.$key.'_type']) === 'percent')>% of daily rate</option>
                            </x-select>
                            <x-input name="deduction_{{ $key }}_amount" :label="$label.' amount'" type="number" step="0.01" min="0" value="{{ old('deduction_'.$key.'_amount', $rules['deduction_'.$key.'_amount']) }}" :error="$errors->first('deduction_'.$key.'_amount')" />
                        </div>
                    @endforeach
                </div>

                <div class="flex items-center gap-2 pt-1">
                    <x-button type="submit" icon="save">Save rules</x-button>
                    <x-button href="{{ route('employees.attendance.index') }}" variant="secondary">Cancel</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>