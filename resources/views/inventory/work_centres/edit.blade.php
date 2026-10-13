<x-app-layout :pageTitle="'Edit work centre'">
    <x-slot name="header">
        <x-page-header :title="$centre->name" :description="'Work centre '.$centre->code" icon="settings">
            <x-slot name="actions">
                <x-button href="{{ route('inventory.work_centres.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <form method="POST" action="{{ route('inventory.work_centres.update', $centre) }}">
        @csrf
        @method('PUT')
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <x-card title="Work centre">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <x-input-label for="code" value="Code" />
                            <x-input id="code" name="code" required value="{{ old('code', $centre->code) }}" />
                        </div>
                        <div>
                            <x-input-label for="name" value="Name" />
                            <x-input id="name" name="name" required value="{{ old('name', $centre->name) }}" />
                        </div>
                        <div>
                            <x-select name="type" label="Type" required>
                                @foreach ($types as $type)
                                    <option value="{{ $type }}" @selected(old('type', $centre->type) === $type)>{{ ucfirst($type) }}</option>
                                @endforeach
                            </x-select>
                        </div>
                        <div>
                            <x-input-label for="hourly_rate" value="Hourly rate" />
                            <x-input id="hourly_rate" name="hourly_rate" type="number" step="0.0001" min="0" required
                                     value="{{ old('hourly_rate', $centre->hourly_rate) }}" />
                        </div>
                        <div>
                            <x-input-label for="capacity_minutes_per_day" value="Minutes available per day" />
                            <x-input id="capacity_minutes_per_day" name="capacity_minutes_per_day" type="number"
                                     min="0" max="1440" value="{{ old('capacity_minutes_per_day', $centre->capacity_minutes_per_day) }}" />
                        </div>
                        <div>
                            <x-input-label for="location" value="Location" />
                            <x-input id="location" name="location" value="{{ old('location', $centre->location) }}" />
                        </div>
                    </div>

                    <div class="mt-4">
                        <x-input-label for="notes" value="Notes" />
                        <textarea id="notes" name="notes" rows="3" class="w-full">{{ old('notes', $centre->notes) }}</textarea>
                    </div>
                </x-card>
            </div>

            <div>
                <x-card title="Save">
                    <label class="flex items-center gap-2 text-sm text-ink">
                        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $centre->is_active)) class="rounded border-line">
                        Active
                    </label>

                    <div class="mt-4 flex gap-2">
                        <x-button type="submit" icon="check">Save</x-button>
                        <x-button href="{{ route('inventory.work_centres.index') }}" variant="ghost">Cancel</x-button>
                    </div>
                </x-card>
            </div>
        </div>
    </form>
</x-app-layout>