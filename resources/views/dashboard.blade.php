<x-app-layout :pageTitle="'Dashboard'">
    <x-slot name="header">
        <x-page-header
            title="{{ now()->format('g:i A') }} — Welcome back, {{ auth()->user()->displayName() }}"
            description="{{ $appBrand['companyName'] }} · {{ now()->format('l, F j, Y') }}"
            icon="dashboard"
        >
            <x-slot name="actions">
                @if ($isAdmin)
                    <x-button href="{{ route('settings.company') }}" variant="secondary" icon="settings">Company settings</x-button>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 space-y-6" x-data="{ loaded: false }" x-init="loaded = true">
        {{-- Skeleton: stat cards --}}
        <div x-show="!loaded" class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach (range(1, 4) as $i)
                <div class="rounded-xl border border-line bg-surface p-5">
                    <div class="h-3 w-20 animate-pulse rounded bg-surface-muted"></div>
                    <div class="mt-3 h-7 w-16 animate-pulse rounded bg-surface-muted"></div>
                </div>
            @endforeach
        </div>
        {{-- Skeleton: activity + modules --}}
        <div x-show="!loaded" class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2 rounded-xl border border-line bg-surface p-5">
                <div class="h-4 w-32 animate-pulse rounded bg-surface-muted"></div>
                <div class="mt-4 space-y-4">
                    @foreach (range(1, 4) as $i)
                        <div class="flex items-start gap-3">
                            <div class="mt-0.5 size-4 shrink-0 animate-pulse rounded-full bg-surface-muted"></div>
                            <div class="flex-1 space-y-2">
                                <div class="h-3 w-3/4 animate-pulse rounded bg-surface-muted"></div>
                                <div class="h-2 w-1/3 animate-pulse rounded bg-surface-muted"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
            <div class="rounded-xl border border-line bg-surface p-5">
                <div class="h-4 w-32 animate-pulse rounded bg-surface-muted"></div>
                <div class="mt-4 space-y-3">
                    @foreach (range(1, 5) as $i)
                        <div class="flex items-center gap-3 rounded-lg px-2 py-1.5">
                            <div class="size-8 animate-pulse rounded-lg bg-surface-muted"></div>
                            <div class="h-3 flex-1 animate-pulse rounded bg-surface-muted"></div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Real content --}}
        <div x-show="loaded" x-cloak>
        {{-- Stat cards --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-stat-card label="Total users" :value="$stats['users']" icon="users" tone="primary" />
            <x-stat-card label="Roles defined" :value="$stats['roles']" icon="roles" tone="info" />
            <x-stat-card label="Modules enabled" :value="$stats['modulesEnabled'].' / '.$stats['modulesTotal']" icon="modules" tone="success" />
            <x-stat-card label="Disabled modules" :value="$disabledModules" icon="archive" tone="warning"
                href="{{ $isAdmin ? route('settings.modules') : null }}"
                hint="{{ $disabledModules ? 'Review in Settings' : 'All modules active' }}" />
        </div>

        <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
            {{-- Activity feed --}}
            @if ($isSuperAdmin)
            <x-card title="Recent activity" description="Latest changes across the company." class="lg:col-span-2">
                @if ($recentActivity->isEmpty())
                    <x-empty-state icon="activity" title="No activity yet" description="Actions you take across modules will show up here." />
                @else
                    <ol class="relative space-y-5 before:absolute before:inset-y-0 before:left-[7px] before:w-px before:bg-line">
                        @foreach ($recentActivity as $log)
                            <li class="relative flex items-start gap-3 pl-1">
                                <span class="mt-0.5 flex size-[15px] shrink-0 items-center justify-center rounded-full border-2 border-surface bg-primary"></span>
                                <div class="min-w-0">
                                    <p class="text-sm text-ink">
                                        <span class="font-semibold">{{ $log->user?->displayName() ?? 'System' }}</span>
                                        {{ $log->event }} <span class="font-medium">{{ $log->module }}</span>
                                        @if ($log->auditable_id)
                                            <span class="text-ink-faint">#{{ $log->auditable_id }}</span>
                                        @endif
                                    </p>
                                    @if ($log->description)
                                        <p class="mt-0.5 truncate text-xs text-ink-faint">{{ $log->description }}</p>
                                    @endif
                                    <p class="mt-0.5 text-xs text-ink-faint">{{ $log->created_at?->diffForHumans() }}</p>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                @endif

                @if (auth()->user()->can('settings.audit.view'))
                    <div class="mt-5 border-t border-line pt-4">
                        <a href="{{ route('settings.audit-log') }}" class="link inline-flex items-center gap-1 text-sm">
                            View full audit log
                            <x-icon name="arrow-right" class="size-4" />
                        </a>
                    </div>
                @endif
            </x-card>
            @endif

            {{-- Module status --}}
            <x-card title="Enabled modules" description="This company's active modules." class="{{ $isSuperAdmin ? '' : 'lg:col-span-3' }}">
                <ul class="space-y-2.5">
                    @foreach ($enabledModules as $module)
                        <li class="flex items-center gap-3 rounded-lg px-2 py-1.5 transition-colors hover:bg-surface-muted">
                            <div class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                <x-icon :name="$module->icon" class="size-4" />
                            </div>
                            <span class="min-w-0 flex-1 truncate text-sm font-medium text-ink">{{ $module->label }}</span>
                            @if ($module->is_core)
                                <x-badge color="info">Core</x-badge>
                            @else
                                <x-badge color="success">On</x-badge>
                            @endif
                        </li>
                    @endforeach
                </ul>

                @if (auth()->user()->can('settings.modules.view'))
                    <div class="mt-4 border-t border-line pt-4">
                        <a href="{{ route('settings.modules') }}" class="link inline-flex items-center gap-1 text-sm">
                            Manage modules
                            <x-icon name="arrow-right" class="size-4" />
                        </a>
                    </div>
                @endif
            </x-card>
        </div>
        </div> {{-- end real content --}}
    </div>
</x-app-layout>
