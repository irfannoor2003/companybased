<x-app-layout page-title="Dashboard">
    <x-slot name="header">
        <x-page-header
            title="Welcome back, {{ auth()->user()->displayName() }}"
            description="{{ $roleLabel }} workspace · {{ now()->format('l, F j, Y') }}"
            icon="dashboard"
        >
            <x-slot name="actions">
                {{--
                    Only Admins manage the per-role dashboard defaults. Super Admin
                    is explicitly barred from settings.dashboards.* (see
                    DashboardPreferenceController::ensureCompanyRole) because its
                    own dashboard is fully customisable, so it only ever gets the
                    personal "Customize mine" action.
                --}}
                @if ($isAdmin && ! auth()->user()->isSuperAdmin())
                    <x-button href="{{ route('settings.dashboards.index') }}" variant="secondary" icon="settings">Manage role dashboards</x-button>
                @endif
                <x-button href="{{ route('dashboard.customize') }}" variant="primary" icon="settings">Customize mine</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="space-y-6">
        <section class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-primary via-primary-strong to-accent p-6 text-white shadow-lg shadow-primary/10 sm:p-8">
            <div class="relative z-10 max-w-3xl">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="rounded-full bg-white/15 px-3 py-1 text-xs font-semibold uppercase tracking-[0.16em]">{{ $roleLabel }}</span>
                    <span class="rounded-full bg-white/10 px-3 py-1 text-xs text-white/80">{{ count($widgets) }} active widgets</span>
                </div>
                <h2 class="mt-4 text-2xl font-bold tracking-tight sm:text-3xl">Your operating cockpit</h2>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-white/80">{{ $roleDescription ?: 'A focused view of the work and information that matters most to your role.' }}</p>
            </div>
            <div class="pointer-events-none absolute -right-16 -top-20 size-64 rounded-full border-[28px] border-white/10"></div>
            <div class="pointer-events-none absolute -bottom-28 right-24 size-72 rounded-full border-[40px] border-white/10"></div>
        </section>

        @if (count($widgets) === 0)
            <x-card title="Your dashboard is ready to configure" description="An administrator needs to assign widgets to your role.">
                <x-empty-state icon="dashboard" title="No widgets assigned" description="Ask an administrator to customize the dashboard for your role." />
            </x-card>
        @else
            <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
                @foreach ($widgets as $widget)
                    @php
                        $items = $widget['data']['items'] ?? [];
                        $metrics = $widget['data']['metrics'] ?? [];
                    @endphp

                    @if ($widget['type'] === 'chart')
                        <x-card title="{{ $widget['label'] }}" description="{{ $widget['description'] }}" class="h-full">
                            <x-slot name="actions"><div class="flex size-9 items-center justify-center rounded-lg bg-primary/10 text-primary"><x-icon :name="$widget['icon']" class="size-4" /></div></x-slot>
                            <div class="h-72">
                                <x-base-chart
                                    type="line"
                                    :data="json_encode($widget['data']['chart']['data'])"
                                    :options="json_encode($widget['data']['chart']['options'])"
                                    height="280"
                                />
                            </div>
                        </x-card>
                    @elseif ($widget['type'] === 'metrics' || $widget['type'] === 'progress')
                        <x-card title="{{ $widget['label'] }}" description="{{ $widget['description'] }}" class="h-full">
                            <x-slot name="actions">
                                <div class="flex size-9 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                    <x-icon :name="$widget['icon']" class="size-4" />
                                </div>
                            </x-slot>
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                @foreach ($items as $item)
                                    @php
                                        $toneClasses = [
                                            'primary' => 'bg-primary/10 text-primary',
                                            'success' => 'bg-emerald-100 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-400',
                                            'warning' => 'bg-amber-100 text-amber-600 dark:bg-amber-500/15 dark:text-amber-400',
                                            'danger' => 'bg-rose-100 text-rose-600 dark:bg-rose-500/15 dark:text-rose-400',
                                            'info' => 'bg-sky-100 text-sky-600 dark:bg-sky-500/15 dark:text-sky-400',
                                        ];
                                    @endphp
                                    <div class="rounded-xl border border-line bg-surface-muted/40 p-4">
                                        <p class="text-lg font-bold tracking-tight text-ink">{{ $item['value'] }}</p>
                                        <p class="mt-1 text-xs text-ink-soft">{{ $item['label'] }}</p>
                                        <span class="mt-3 inline-flex size-2 rounded-full {{ $toneClasses[$item['tone'] ?? 'primary'] ?? $toneClasses['primary'] }}"></span>
                                    </div>
                                @endforeach
                            </div>
                            @if ($widget['type'] === 'progress' && isset($widget['data']['total']))
                                <div class="mt-5 flex items-center justify-between border-t border-line pt-4 text-xs">
                                    <span class="text-ink-soft">Current workload</span>
                                    <span class="font-semibold text-ink">{{ $widget['data']['total'] }} total</span>
                                </div>
                            @endif
                            @if (! empty($widget['data']['href']))
                                <div class="mt-5 border-t border-line pt-4">
                                    <a href="{{ $widget['data']['href'] }}" class="link inline-flex items-center gap-1 text-sm">Open related workspace <x-icon name="arrow-right" class="size-4" /></a>
                                </div>
                            @endif
                        </x-card>
                    @elseif ($widget['type'] === 'actions')
                        <x-card title="{{ $widget['label'] }}" description="{{ $widget['description'] }}" class="h-full">
                            <x-slot name="actions"><x-icon :name="$widget['icon']" class="size-5 text-primary" /></x-slot>
                            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                @foreach ($items as $action)
                                    <a href="{{ $action['href'] }}" class="group flex items-center gap-3 rounded-xl border border-line p-3 transition hover:border-primary/30 hover:bg-primary/5">
                                        <span class="flex size-9 items-center justify-center rounded-lg bg-primary/10 text-primary"><x-icon :name="$action['icon']" class="size-4" /></span>
                                        <span class="flex-1 text-sm font-semibold text-ink">{{ $action['label'] }}</span>
                                        <x-icon name="arrow-right" class="size-4 text-ink-faint transition group-hover:translate-x-0.5 group-hover:text-primary" />
                                    </a>
                                @endforeach
                            </div>
                        </x-card>
                    @else
                        <x-card title="{{ $widget['label'] }}" description="{{ $widget['description'] }}" class="h-full" :padding="false">
                            <x-slot name="actions">
                                <div class="flex size-9 items-center justify-center rounded-lg bg-primary/10 text-primary"><x-icon :name="$widget['icon']" class="size-4" /></div>
                            </x-slot>
                            <div class="divide-y divide-line">
                                @forelse ($items as $item)
                                    <div class="flex items-center gap-3 px-5 py-3.5">
                                        <div class="min-w-0 flex-1">
                                            <p class="truncate text-sm font-semibold text-ink">{{ $item['title'] }}</p>
                                            <p class="mt-0.5 truncate text-xs text-ink-faint">{{ $item['meta'] }}</p>
                                        </div>
                                        <span class="shrink-0 text-xs font-bold {{ ($item['tone'] ?? '') === 'danger' ? 'text-rose-500' : (($item['tone'] ?? '') === 'warning' ? 'text-amber-500' : 'text-ink') }}">{{ $item['value'] }}</span>
                                    </div>
                                @empty
                                    <div class="px-5 py-8"><x-empty-state icon="{{ $widget['icon'] }}" title="Nothing to show yet" description="New activity will appear here." /></div>
                                @endforelse
                            </div>
                            @if (count($metrics) > 0)
                                <div class="flex flex-wrap gap-4 border-t border-line bg-surface-muted/30 px-5 py-3">
                                    @foreach ($metrics as $metric)
                                        <span class="text-xs text-ink-soft"><strong class="text-ink">{{ $metric['value'] }}</strong> {{ strtolower($metric['label']) }}</span>
                                    @endforeach
                                </div>
                            @endif
                            @if (! empty($widget['data']['href']))
                                <div class="border-t border-line px-5 py-3">
                                    <a href="{{ $widget['data']['href'] }}" class="link inline-flex items-center gap-1 text-sm">View all <x-icon name="arrow-right" class="size-4" /></a>
                                </div>
                            @endif
                        </x-card>
                    @endif
                @endforeach
            </div>
        @endif
    </div>
</x-app-layout>
