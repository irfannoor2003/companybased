<x-app-layout :pageTitle="$report->name">
    <x-slot name="header">
        <x-page-header
            :title="$report->name"
            :description="$report->description ?: 'Custom '.$moduleLabel.' report'"
            icon="reports"
        >
            <x-slot name="actions">
                <x-button href="{{ route('reports.custom.create', ['from' => $report->id]) }}" variant="secondary" icon="edit">Edit</x-button>
                <x-button href="{{ route('reports.custom.index') }}" variant="ghost" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <x-card class="mb-4">
        <div class="flex flex-wrap gap-4 text-sm">
            <div>
                <span class="text-ink-faint">Module:</span>
                <span class="ml-1 font-medium capitalize text-ink">{{ $moduleLabel }}</span>
            </div>
            <div>
                <span class="text-ink-faint">Columns:</span>
                <span class="ml-1 font-medium text-ink">{{ count($report->fields ?? []) }}</span>
            </div>
            <div>
                <span class="text-ink-faint">Filters:</span>
                <span class="ml-1 font-medium text-ink">{{ count($report->filters ?? []) }}</span>
            </div>
            <div>
                <span class="text-ink-faint">Results:</span>
                <span class="ml-1 font-medium text-ink">{{ count($rows) }}</span>
            </div>
        </div>
    </x-card>

    <x-card :padding="false">
        @if (empty($rows))
            <x-empty-state
                icon="reports"
                title="No results"
                description="No data matches this report's definition. Try editing the report or adding data to this module."
            />
        @else
            <div class="table-wrap !border-0 !rounded-none">
                <table class="table-base">
                    <thead>
                        <tr>
                            @foreach ($report->fields ?? [] as $field)
                                <th>{{ ucfirst(str_replace('_', ' ', $field)) }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                @foreach ($report->fields ?? [] as $field)
                                    <td class="text-ink-soft">{{ $row[$field] ?? '—' }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-card>
</x-app-layout>
