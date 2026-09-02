<x-app-layout :pageTitle="'Reports'">
    <x-slot name="header">
        <x-page-header
            title="Reports"
            description="Financial, sales, inventory, HR and asset reporting — every report supports the same date-range filter."
            icon="reports"
        >
            @if (auth()->user()->can('reports.reports.export'))
                <x-slot name="actions">
                    <x-button variant="secondary" icon="export">Export current view</x-button>
                </x-slot>
            @endif
        </x-page-header>
    </x-slot>

    {{-- Consistent filter control across all report views --}}
    <x-card class="mb-6">
        <x-report-filter />
    </x-card>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($groups as $group)
            <div class="surface-card flex flex-col p-5">
                <div class="flex items-center gap-3">
                    <div class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                        <x-icon :name="$group['icon']" class="size-5" />
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-ink">{{ $group['label'] }}</p>
                        @if (! empty($group['reports']))
                            <p class="text-xs text-ink-faint">{{ count($group['reports']) }} report{{ count($group['reports']) === 1 ? '' : 's' }}</p>
                        @endif
                    </div>
                </div>
                <p class="mt-3 flex-1 text-sm text-ink-soft">{{ $group['description'] }}</p>

                @if (! empty($group['reports']))
                    <ul class="mt-4 space-y-1">
                        @foreach ($group['reports'] as $report)
                            <li class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm text-ink-soft transition-colors hover:bg-surface-muted hover:text-ink">
                                <x-icon name="document" class="size-4 text-ink-faint" />
                                {{ $report }}
                            </li>
                        @endforeach
                    </ul>
                @endif

                <div class="mt-4 border-t border-line pt-4">
                    @if (! empty($group['custom']))
                        <a href="{{ route('reports.custom.index') }}" class="link inline-flex items-center gap-1 text-sm">
                            Open report builder
                            <x-icon name="arrow-right" class="size-4" />
                        </a>
                    @elseif (! empty($group['route']))
                        <a href="{{ route($group['route']) }}" class="link inline-flex items-center gap-1 text-sm">
                            View reports
                            <x-icon name="arrow-right" class="size-4" />
                        </a>
                    @else
                        <button type="button" class="link inline-flex items-center gap-1 text-sm disabled:cursor-not-allowed disabled:opacity-50"
                            title="Coming in the Reports milestone" disabled>
                            View reports
                            <x-icon name="arrow-right" class="size-4" />
                        </button>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

</x-app-layout>
