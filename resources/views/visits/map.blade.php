<x-app-layout :pageTitle="'Visit Map'">
    <x-slot name="header">
        <x-page-header title="Visit Map" description="GPS route history of visits and pit-stops." icon="map">
            <x-slot name="actions">
                @if (auth()->user()->can('visits.map_view.export'))
                    <x-button href="{{ route('visits.map.export', array_merge(request()->query(), ['format' => 'csv'])) }}" variant="secondary" icon="download">CSV</x-button>
                    <x-button href="{{ route('visits.map.export', array_merge(request()->query(), ['format' => 'json'])) }}" variant="secondary" icon="download">JSON</x-button>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    @include('visits._tabs')

    <div class="mt-6">
        <x-card :padding="false">
            <form method="GET" action="{{ route('visits.map') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
                <div class="w-44">
                    <x-input name="from" label="From" type="date" value="{{ $from }}" size="sm" />
                </div>
                <div class="w-44">
                    <x-input name="to" label="To" type="date" value="{{ $to }}" size="sm" />
                </div>
                <x-button type="submit" size="sm" icon="filter">Apply</x-button>
                <x-button href="{{ route('visits.map') }}" variant="ghost" size="sm">Reset</x-button>
            </form>

            @if ($markers->isEmpty())
                <x-empty-state icon="map" title="No coordinates" description="Add start coordinates or pit-stop locations with GPS data to see them on the map." />
            @else
                <div class="h-[600px] w-full !rounded-none !border-0" id="visit-map"></div>

                <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
                <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
                <script>
                    const markers = @js($markers);

                    const map = L.map('visit-map').setView([{{ settings('company.latitude', '0') }}, {{ settings('company.longitude', '0') }}], 8);

                    /**
                     * Two tile layers, swapped on theme change.
                     *
                     * OpenStreetMap's standard raster is light-only, so a dark-mode
                     * user got a glaring white map in the middle of a dark page --
                     * a canvas surface the `dark` class cannot reach. CARTO's dark
                     * tiles are keyless and need their own attribution.
                     */
                    const TILES = {
                        light: {
                            url: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
                            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
                        },
                        dark: {
                            url: 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png',
                            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors &copy; <a href="https://carto.com/attributions">CARTO</a>',
                        },
                    };

                    const isDarkTheme = () => document.documentElement.classList.contains('dark');
                    let tiles = L.tileLayer(TILES[isDarkTheme() ? 'dark' : 'light'].url, {
                        maxZoom: 19,
                        attribution: TILES[isDarkTheme() ? 'dark' : 'light'].attribution,
                    }).addTo(map);

                    document.addEventListener('cb:themechange', () => {
                        const next = TILES[isDarkTheme() ? 'dark' : 'light'];
                        map.removeLayer(tiles);
                        tiles = L.tileLayer(next.url, { maxZoom: 19, attribution: next.attribution }).addTo(map);
                        tiles.bringToBack();
                    });

                    const icons = {
                        start: L.divIcon({
                            className: '',
                            // Brand token and surface border rather than a fixed
                            // indigo-600/border-white, so the pins match the tenant's
                            // colour and do not sit as white rings on dark tiles.
                            html: '<div class="flex size-7 items-center justify-center rounded-full bg-[rgb(var(--color-primary))] text-white text-xs font-bold border-2 border-[rgb(var(--color-surface))] shadow">S</div>',
                            iconSize: [28, 28],
                            iconAnchor: [14, 14],
                        }),
                        pitstop: L.divIcon({
                            className: '',
                            html: '<div class="flex size-7 items-center justify-center rounded-full bg-[rgb(var(--color-series-warning))] text-white text-xs font-bold border-2 border-[rgb(var(--color-surface))] shadow">P</div>',
                            iconSize: [28, 28],
                            iconAnchor: [14, 14],
                        }),
                    };

                    const bounds = [];
                    markers.forEach((m) => {
                        const marker = L.marker([m.lat, m.lng], { icon: icons[m.type] || icons.pitstop }).addTo(map);
                        marker.bindPopup(
                            `<strong>${m.visit}</strong><br>${m.customer}<br>${m.type === 'start' ? 'Start point' : 'Pit-stop'}`
                        );
                        bounds.push([m.lat, m.lng]);
                    });

                    if (bounds.length > 1) {
                        map.fitBounds(bounds, { padding: [40, 40] });
                    }
                </script>
            @endif
        </x-card>
    </div>
</x-app-layout>