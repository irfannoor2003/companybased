<x-app-layout :pageTitle="'Custom Reports'">
    <x-slot name="header">
        <x-page-header
            title="Custom Reports"
            description="Save and reuse custom report definitions for any module."
            icon="reports"
        >
            <x-slot name="actions">
                <x-button href="{{ route('reports.custom.create') }}" icon="plus">New report</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <x-card :padding="false">
        @if ($reports->isEmpty())
            <x-empty-state
                icon="reports"
                title="No saved reports yet"
                description="Create a custom report to pick a data source, choose fields and apply filters."
                :action="['label' => 'Create report', 'href' => route('reports.custom.create')]"
            />
        @else
            <div class="table-wrap !border-0 !rounded-none">
                <table class="table-base">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Module</th>
                            <th>Fields</th>
                            <th>Created</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($reports as $report)
                            <tr>
                                <td>
                                    <span class="font-medium text-ink">{{ $report->name }}</span>
                                    @if ($report->description)
                                        <p class="text-xs text-ink-faint">{{ $report->description }}</p>
                                    @endif
                                </td>
                                <td>
                                    <x-badge variant="primary">{{ ucfirst($report->module) }}</x-badge>
                                </td>
                                <td class="text-ink-soft">
                                    {{ count($report->fields ?? []) }} field(s)
                                </td>
                                <td class="text-ink-soft">{{ $report->created_at->format('Y-m-d') }}</td>
                                <td class="text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        <a href="{{ route('reports.custom.create', ['from' => $report->id]) }}"
                                           class="btn-ghost btn-icon btn-sm" title="Duplicate & Edit">
                                            <x-icon name="edit" class="size-4" />
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($reports->hasPages())
            <div class="px-5 py-4">
                {{ $reports->links() }}
            </div>
        @endif
    </x-card>
</x-app-layout>
