<x-app-layout :pageTitle="'New work centre'">
    <x-slot name="header">
        <x-page-header title="New work centre"
                       description="A machine, bench or line work happens at — and what it costs to run."
                       icon="settings">
            <x-slot name="actions">
                <x-button href="{{ route('inventory.work_centres.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <form method="POST" action="{{ route('inventory.work_centres.store') }}">
        @csrf
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <x-card title="Work centre">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <x-input-label for="code" value="Code" />
                            <x-input id="code" name="code" required value="{{ old('code') }}" placeholder="MACHINE-01" />
                            <p class="mt-1 text-xs text-ink-faint">Short identifier, unique across work centres.</p>
                        </div>
                        <div>
                            <x-input-label for="name" value="Name" />
                            <x-input id="name" name="name" required value="{{ old('name') }}" placeholder="CNC Mill 1" />
                        </div>
                        <div>
                            <x-select name="type" label="Type" required>
                                @foreach ($types as $type)
                                    <option value="{{ $type }}" @selected(old('type', 'machine') === $type)>{{ ucfirst($type) }}</option>
                                @endforeach
                            </x-select>
                            {{-- Stated rather than implied by the name: a machine hour
                                 and a labour hour are different units, and the rate
                                 below means something different for each. --}}
                            <p class="mt-1 text-xs text-ink-faint">
                                Machine and labour hours are different units — pick the one this centre measures.
                            </p>
                        </div>
                        <div>
                            <x-input-label for="hourly_rate" value="Hourly rate" />
                            <x-input id="hourly_rate" name="hourly_rate" type="number" step="0.0001" min="0" required
                                     value="{{ old('hourly_rate', '0') }}" />
                            <p class="mt-1 text-xs text-ink-faint">Cost to run this centre for one hour.</p>
                        </div>
                        <div>
                            <x-input-label for="capacity_minutes_per_day" value="Minutes available per day" />
                            <x-input id="capacity_minutes_per_day" name="capacity_minutes_per_day" type="number"
                                     min="0" max="1440" value="{{ old('capacity_minutes_per_day') }}" />
                            <p class="mt-1 text-xs text-ink-faint">
                                Leave blank for an unconstrained centre. A figure here is what the capacity
                                report measures against, so an invented number produces invented overloads.
                            </p>
                        </div>
                        <div>
                            <x-input-label for="location" value="Location" />
                            <x-input id="location" name="location" value="{{ old('location') }}" />
                        </div>
                    </div>

                    <div class="mt-4">
                        <x-input-label for="notes" value="Notes" />
                        <textarea id="notes" name="notes" rows="3" class="w-full">{{ old('notes') }}</textarea>
                    </div>
                </x-card>
            </div>

            <div>
                <x-card title="Save">
                    <label class="flex items-center gap-2 text-sm text-ink">
                        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', true)) class="rounded border-line">
                        Active
                    </label>
                    <p class="mt-2 text-xs text-ink-faint">
                        A retired centre stops taking new work but keeps its history, so
                        completed operations are never orphaned.
                    </p>

                    <div class="mt-4 flex gap-2">
                        <x-button type="submit" icon="check">Create</x-button>
                        <x-button href="{{ route('inventory.work_centres.index') }}" variant="ghost">Cancel</x-button>
                    </div>
                </x-card>
            </div>
        </div>
    </form>
</x-app-layout>