<x-settings-layout page-title="Audit log">
    <x-page-header title="Audit log" description="Every create, update and delete across all modules, tracked with before/after values." icon="audit">
        <x-slot name="actions">
            @if (auth()->user()->can('settings.audit.export'))
                <x-button href="{{ route('settings.audit-log.export', request()->query()) }}" variant="secondary" icon="export">Export CSV</x-button>
            @endif
        </x-slot>
    </x-page-header>

    <div class="mt-6">
        <x-card :padding="false">
            <form method="GET" action="{{ route('settings.audit-log') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
                <div class="w-44">
                    <x-select name="module" label="Module" size="sm">
                        <option value="">All modules</option>
                        @foreach ($modules as $module)
                            <option value="{{ $module }}" @selected(request('module') === $module)>{{ $module }}</option>
                        @endforeach
                    </x-select>
                </div>
                <div class="w-40">
                    <x-select name="event" label="Event" size="sm">
                        <option value="">All events</option>
                        @foreach ($events as $event)
                            <option value="{{ $event }}" @selected(request('event') === $event)>{{ ucfirst($event) }}</option>
                        @endforeach
                    </x-select>
                </div>
                <div class="w-44">
                    <x-input name="user" label="User" placeholder="User name or email…" value="{{ request('user') }}" size="sm" />
                </div>
                <div class="w-40">
                    <x-input name="from" label="From" type="date" value="{{ request('from') }}" size="sm" />
                </div>
                <div class="w-40">
                    <x-input name="to" label="To" type="date" value="{{ request('to') }}" size="sm" />
                </div>
                <div class="flex gap-2">
                    <x-button type="submit" size="sm" icon="filter">Filter</x-button>
                    @if (request()->hasAny(['module', 'event', 'user', 'from', 'to']))
                        <x-button href="{{ route('settings.audit-log') }}" variant="ghost" size="sm">Clear</x-button>
                    @endif
                </div>
            </form>

            @if ($logs->isEmpty())
                <x-empty-state icon="audit" title="No audit entries found" description="Activity will appear here as records are created, updated or deleted." />
            @else
                <div class="table-wrap !border-0 !rounded-none">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th>When</th>
                                <th>User</th>
                                <th>Module</th>
                                <th>Event</th>
                                <th>Entity</th>
                                <th>Description</th>
                                <th>IP</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($logs as $log)
                                <tr>
                                    <td class="text-ink-soft">{{ $log->created_at?->format('M d, Y H:i') }}</td>
                                    <td>
                                        <span class="font-medium text-ink">{{ $log->user?->displayName() ?? 'System' }}</span>
                                    </td>
                                    <td>
                                        <x-badge color="neutral">{{ $log->module }}</x-badge>
                                    </td>
                                    <td>
                                        @php
                                            $colors = ['created' => 'success', 'updated' => 'info', 'deleted' => 'danger', 'restored' => 'warning'];
                                        @endphp
                                        <x-badge :color="$colors[$log->event] ?? 'neutral'">{{ ucfirst($log->event) }}</x-badge>
                                    </td>
                                    <td class="text-ink-soft">
                                        {{ class_basename((string) $log->auditable_type) }}{{ $log->auditable_id ? ' #'.$log->auditable_id : '' }}
                                    </td>
                                    <td class="max-w-xs truncate !whitespace-normal text-ink-soft">{{ $log->description }}</td>
                                    <td class="text-xs text-ink-faint">{{ $log->ip_address }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($logs->hasPages())
                <div class="px-5 py-4">
                    {{ $logs->links() }}
                </div>
            @endif
        </x-card>
    </div>
</x-settings-layout>
