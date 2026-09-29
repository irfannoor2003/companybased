<x-app-layout page-title="Holidays">
    <x-slot name="header">
        <x-page-header title="Holiday calendar" description="Manage company holidays used by attendance and payroll." icon="calendar">
            <x-slot name="actions"><x-button href="{{ route('employees.attendance.index') }}" variant="secondary" icon="arrow-left">Attendance</x-button></x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_360px]">
        <x-card title="{{ $year }} holidays" description="Default Pakistan holidays are preloaded. Add company-specific holidays as needed." :padding="false">
            <div class="flex flex-wrap gap-3 border-b border-line p-4">
                <a href="{{ route('employees.holidays.index', ['year' => $year - 1]) }}" class="btn-ghost btn-icon btn-sm"><x-icon name="chevron-left" class="size-4" /></a>
                <span class="flex-1 text-center text-sm font-semibold text-ink">{{ $year }}</span>
                <a href="{{ route('employees.holidays.index', ['year' => $year + 1]) }}" class="btn-ghost btn-icon btn-sm"><x-icon name="chevron-right" class="size-4" /></a>
            </div>
            <div class="divide-y divide-line">
                @forelse ($holidays as $holiday)
                    <div class="flex items-center gap-4 px-5 py-4">
                        <div class="w-16 shrink-0 rounded-xl bg-primary/10 p-2 text-center">
                            <p class="text-lg font-bold text-primary">{{ $holiday->holiday_date->format('d') }}</p>
                            <p class="text-[10px] font-semibold uppercase text-primary">{{ $holiday->holiday_date->format('M') }}</p>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold text-ink">{{ $holiday->name }} @if ($holiday->is_default)<x-badge color="info">Default</x-badge>@endif</p>
                            <p class="mt-1 text-xs text-ink-faint">{{ $holiday->description ?: 'No description' }}</p>
                        </div>
                        <x-badge :color="$holiday->is_active ? 'success' : 'neutral'">{{ $holiday->is_active ? 'Active' : 'Inactive' }}</x-badge>
                        @if (auth()->user()->can('employees.holidays.manage'))
                            <form method="POST" action="{{ route('employees.holidays.destroy', $holiday) }}" onsubmit="return confirm('Remove this holiday?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn-ghost btn-icon btn-sm text-rose-500"><x-icon name="trash" class="size-4" /></button>
                            </form>
                        @endif
                    </div>
                @empty
                    <div class="p-8"><x-empty-state icon="calendar" title="No holidays" description="Add the first holiday for this year." /></div>
                @endforelse
            </div>
        </x-card>

        @if (auth()->user()->can('employees.holidays.manage'))
            <x-card title="Add holiday" description="This day will be excluded from attendance and payroll working days.">
                <form method="POST" action="{{ route('employees.holidays.store') }}" class="space-y-4">
                    @csrf
                    <x-input name="holiday_date" label="Date" type="date" required :error="$errors->first('holiday_date')" />
                    <x-input name="name" label="Holiday name" required placeholder="e.g. Company Foundation Day" :error="$errors->first('name')" />
                    <x-textarea name="description" label="Description" placeholder="Optional note for HR and payroll" />
                    <label class="flex items-center gap-2 text-sm text-ink-soft"><input type="checkbox" name="is_active" value="1" checked class="size-4 rounded border-line text-primary"> Mark as active</label>
                    <x-button type="submit" class="w-full" icon="plus">Add holiday</x-button>
                </form>
            </x-card>
        @endif
    </div>
</x-app-layout>
