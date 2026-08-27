<x-app-layout :pageTitle="$visit->visit_number">
    <x-slot name="header">
        <x-page-header :title="$visit->visit_number" :description="$visit->customer?->company_name.' · '.($visit->salesRep?->fullName() ?: 'Unassigned')" icon="visits">
            <x-slot name="actions">
                @if (auth()->user()->can('visits.pit_stops.export') && $visit->pitStops->isNotEmpty())
                    <x-button href="{{ route('visits.pitstops.export', ['visit' => $visit->id, 'format' => 'csv']) }}" variant="secondary" icon="download">Export stops</x-button>
                @endif
                @if (auth()->user()->can('visits.visits.edit') && $visit->status === 'pending')
                    <x-button href="{{ route('visits.edit', $visit) }}" variant="secondary" icon="tag">Edit</x-button>
                @endif
                <x-button href="{{ route('visits.index') }}" variant="ghost" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    @include('visits._tabs')

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-card title="Visit details">
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between"><dt class="text-ink-faint">Number</dt><dd class="font-mono text-ink">{{ $visit->visit_number }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-faint">Customer</dt><dd class="text-ink">{{ $visit->customer?->company_name ?: '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-faint">Sales rep</dt><dd class="text-ink">{{ $visit->salesRep?->fullName() ?: '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-faint">Scheduled</dt><dd class="text-ink">{{ $visit->scheduled_at?->format('Y-m-d') ?: '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-faint">Status</dt><dd><x-visits.status-badge :status="$visit->status" /></dd></div>
                <div class="flex justify-between"><dt class="text-ink-faint">Purpose</dt><dd class="text-ink">{{ $visit->purpose ?: '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-faint">Distance</dt><dd class="text-ink">{{ $visit->distance_km ? $visit->distance_km.' km' : '—' }}</dd></div>
                @if ($visit->outcome)
                    <div class="flex justify-between"><dt class="text-ink-faint">Outcome</dt><dd><x-visits.status-badge :status="$visit->outcome" /></dd></div>
                @endif
                @if ($visit->notes)
                    <div class="border-t border-line pt-3"><dt class="text-ink-faint">Notes</dt><dd class="mt-1 whitespace-pre-line text-ink-soft">{{ $visit->notes }}</dd></div>
                @endif
            </dl>
        </x-card>

        <div class="space-y-6 lg:col-span-2">
            @if (auth()->user()->can('visits.visits.edit') && $visit->status !== 'completed' && $visit->status !== 'cancelled')
                <x-card title="Advance visit" x-data="visitLocation()">
                    <div class="flex flex-wrap items-end gap-3">
                        @if ($visit->status === 'pending')
                            <form method="POST" action="{{ route('visits.start', $visit) }}" id="startForm">
                                @csrf
                                <input type="hidden" name="latitude" :value="latitude" />
                                <input type="hidden" name="longitude" :value="longitude" />
                                <x-button type="submit" icon="zap" @click="getLocation($event)">Start visit</x-button>
                            </form>
                        @endif
                        @if ($visit->status === 'started')
                            <form method="POST" action="{{ route('visits.complete', $visit) }}" id="completeForm" class="flex flex-wrap items-end gap-3">
                                @csrf
                                <input type="hidden" name="latitude" :value="latitude" />
                                <input type="hidden" name="longitude" :value="longitude" />
                                <div class="w-56">
                                    <x-select name="outcome" label="Outcome" required size="sm">
                                        @foreach (\App\Models\Visit::outcomeOptions() as $outcome)
                                            <option value="{{ $outcome }}">{{ ucfirst(str_replace('_', ' ', $outcome)) }}</option>
                                        @endforeach
                                    </x-select>
                                </div>
                                <x-button type="submit" variant="success" size="md" icon="check" @click="getLocation($event)">Complete visit</x-button>
                            </form>
                        @endif
                    </div>
                    <template x-if="error">
                        <p class="mt-2 text-sm text-red-600" x-text="error"></p>
                    </template>
                </x-card>
            @endif

            @if (auth()->user()->can('visits.visits.edit') && in_array($visit->status, ['pending', 'started'], true))
                <x-card title="Cancel visit" description="This hides the visit from the active list.">
                    <form method="POST" action="{{ route('visits.cancel', $visit) }}" onsubmit="return confirm('Cancel this visit?')" class="flex flex-wrap items-end gap-3">
                        @csrf
                        <div class="min-w-[240px] flex-1">
                            <x-input name="cancel_reason" label="Reason (optional)" size="sm" placeholder="e.g. customer unavailable" />
                        </div>
                        <x-button type="submit" variant="danger-secondary" size="sm" icon="x">Cancel visit</x-button>
                    </form>
                </x-card>
            @endif

            <x-card title="Pit-stops" :padding="false">
                @if ($visit->pitStops->isEmpty())
                    <p class="px-5 py-6 text-sm text-ink-faint">No pit-stops recorded for this visit.</p>
                @else
                    <ul class="divide-y divide-line">
                        @foreach ($visit->pitStops as $i => $stop)
                            <li x-data="{ editing: false }">
                                <div class="flex items-start justify-between gap-3 px-5 py-3">
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium text-ink">
                                            <span class="mr-2 inline-flex size-5 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary">{{ $i + 1 }}</span>
                                            {{ $stop->customer?->company_name ?: 'Direct' }}
                                        </p>
                                        <p class="mt-0.5 text-xs text-ink-faint">
                                            {{ $stop->visited_at?->format('M d, Y H:i') }}
                                            @if ($stop->purpose) · {{ $stop->purpose }} @endif
                                            @if ($stop->distance_km) · {{ $stop->distance_km }} km @endif
                                        </p>
                                        @if ($stop->notes)
                                            <p class="mt-1 text-sm text-ink-soft">{{ $stop->notes }}</p>
                                        @endif
                                    </div>
                                    <div class="flex shrink-0 items-center gap-1">
                                        <a href="{{ route('visits.map') }}" class="btn-ghost btn-icon btn-sm" title="On map">
                                            <x-icon name="map" class="size-4" />
                                        </a>
                                        @if ($stop->image_path)
                                            <a href="{{ route('visits.pitstops.image', $stop) }}" target="_blank" class="btn-ghost btn-icon btn-sm" title="Photo">
                                                <x-icon name="eye" class="size-4" />
                                            </a>
                                        @endif
                                        @if (auth()->user()->can('visits.pit_stops.edit'))
                                            <button type="button" class="btn-ghost btn-icon btn-sm" title="Edit" @click="editing = !editing">
                                                <x-icon name="tag" class="size-4" />
                                            </button>
                                        @endif
                                        @if (auth()->user()->can('visits.pit_stops.delete'))
                                            <form method="POST" action="{{ route('visits.pitstops.destroy', [$visit, $stop]) }}" onsubmit="return confirm('Remove this pit-stop?')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn-ghost btn-icon btn-sm text-rose-500" title="Remove">
                                                    <x-icon name="trash" class="size-4" />
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </div>

                                @if (auth()->user()->can('visits.pit_stops.edit'))
                                    <div x-show="editing" x-cloak class="border-t border-line bg-surface-muted/40 px-5 py-4">
                                        <form method="POST" action="{{ route('visits.pitstops.update', [$visit, $stop]) }}" enctype="multipart/form-data" class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                            @csrf
                                            @method('PUT')
                                            <x-select name="customer_id" label="Customer" size="sm">
                                                <option value="">Direct / no customer</option>
                                                @foreach ($customers as $customer)
                                                    <option value="{{ $customer->id }}" @selected($stop->customer_id == $customer->id)>{{ $customer->company_name }}</option>
                                                @endforeach
                                            </x-select>
                                            <x-input name="purpose" label="Purpose" size="sm" value="{{ $stop->purpose }}" />
                                            <x-input name="visited_at" label="Visited at" type="datetime-local" size="sm" value="{{ $stop->visited_at?->format('Y-m-d\TH:i') }}" />
                                            <div class="grid grid-cols-3 gap-3">
                                                <x-input name="distance_km" label="km" type="number" step="0.01" size="sm" value="{{ $stop->distance_km }}" />
                                                <x-input name="lat" label="Lat" type="number" step="any" size="sm" value="{{ $stop->lat }}" />
                                                <x-input name="lng" label="Lng" type="number" step="any" size="sm" value="{{ $stop->lng }}" />
                                            </div>
                                            <div class="sm:col-span-2">
                                                <x-textarea name="notes" label="Notes">{{ $stop->notes }}</x-textarea>
                                            </div>
                                            <div class="sm:col-span-2">
                                                <label class="label" for="image">Photo</label>
                                                <input type="file" name="image" id="image" class="input" />
                                            </div>
                                            <div class="flex gap-2 sm:col-span-2">
                                                <x-button type="submit" size="sm" icon="save">Save pit-stop</x-button>
                                                <x-button type="button" variant="ghost" size="sm" @click="editing = false">Cancel</x-button>
                                            </div>
                                        </form>
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if (auth()->user()->can('visits.pit_stops.create'))
                    <div class="border-t border-line px-5 py-4">
                        <form method="POST" action="{{ route('visits.pitstops.store', $visit) }}" enctype="multipart/form-data" class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            @csrf
                            <x-select name="customer_id" label="Customer" size="sm">
                                <option value="">Direct / no customer</option>
                                @foreach ($customers as $customer)
                                    <option value="{{ $customer->id }}">{{ $customer->company_name }}</option>
                                @endforeach
                            </x-select>
                            <x-input name="purpose" label="Purpose" size="sm" placeholder="e.g. Collect payment" />
                            <x-input name="visited_at" label="Visited at" type="datetime-local" size="sm" value="{{ now()->format('Y-m-d\TH:i') }}" />
                            <div class="grid grid-cols-3 gap-3">
                                <x-input name="distance_km" label="km" type="number" step="0.01" size="sm" placeholder="0.00" />
                                <x-input name="lat" label="Lat" type="number" step="any" size="sm" />
                                <x-input name="lng" label="Lng" type="number" step="any" size="sm" />
                            </div>
                            <div class="sm:col-span-2">
                                <x-textarea name="notes" label="Notes"></x-textarea>
                            </div>
                            <div class="sm:col-span-2">
                                <label class="label" for="image">Photo <span class="font-normal text-ink-faint">(optional)</span></label>
                                <input type="file" name="image" id="image" class="input" />
                            </div>
                            <x-button type="submit" size="sm" icon="plus">Add pit-stop</x-button>
                        </form>
                    </div>
                @endif
            </x-card>
        </div>
    </div>

    @push('scripts')
    <script>
        function visitLocation() {
            return {
                latitude: null,
                longitude: null,
                error: '',

                getLocation(event) {
                    if (navigator.geolocation) {
                        event.preventDefault();
                        navigator.geolocation.getCurrentPosition(
                            (position) => {
                                this.latitude = position.coords.latitude;
                                this.longitude = position.coords.longitude;
                                // Submit the form after getting location
                                event.target.closest('form').submit();
                            },
                            (error) => {
                                this.error = 'Location access is required to start/complete visits. Please enable location permissions.';
                            }
                        );
                    }
                }
            }
        }
    </script>
    @endpush
</x-app-layout>