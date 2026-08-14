@php
    $navItems = collect(config('nav.items', []))->map(function ($item) use ($enabledModuleKeys) {
        $module = $item['module'] ?? null;
        $moduleEnabled = ! $module || in_array($module, $enabledModuleKeys, true);

        if (! empty($item['group'])) {
            $children = collect($item['items'] ?? [])
                ->filter(function ($child) use ($enabledModuleKeys) {
                    $childModule = $child['module'] ?? null;
                    if ($childModule && ! in_array($childModule, $enabledModuleKeys, true)) {
                        return false;
                    }
                    if (($child['permission'] ?? null) && ! auth()->user()->can($child['permission'])) {
                        return false;
                    }
                    return true;
                })
                ->values();

            return ['item' => $item, 'visible' => $moduleEnabled && $children->isNotEmpty(), 'children' => $children];
        }

        $visible = $moduleEnabled;
        if (($item['permission'] ?? null) && ! auth()->user()->can($item['permission'])) {
            $visible = false;
        }

        return ['item' => $item, 'visible' => $visible];
    });

    $isActive = fn ($route) => request()->routeIs($route) || request()->routeIs($route . '.*');
@endphp

{{-- Mobile overlay --}}
<div
    x-show="drawerOpen && !isDesktop"
    x-transition.opacity
    x-cloak
    @click="drawerOpen = false"
    class="fixed inset-0 z-30 bg-slate-950/50 backdrop-blur-sm lg:hidden"
></div>

{{-- Sidebar --}}
    <aside
        x-show="drawerOpen || isDesktop"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="-translate-x-full"
        x-transition:enter-end="translate-x-0"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="translate-x-0"
        x-transition:leave-end="-translate-x-full"
        x-cloak
        @keydown.escape.window="drawerOpen = false"
        class="fixed inset-y-0 left-0 z-40 flex w-64 flex-col border-r border-line bg-surface lg:translate-x-0 lg:shadow-none lg:!flex"
        :class="collapsed ? 'lg:w-16' : 'lg:w-64'"
    >
    {{-- Brand --}}
    <div class="flex h-16 shrink-0 items-center gap-3 border-b border-line px-4">
        <a href="{{ route('dashboard') }}" class="flex min-w-0 items-center gap-3">
            @if ($appBrand['logo'])
                <img src="{{ Storage::url($appBrand['logo']) }}" alt="{{ $appBrand['companyName'] }}" class="h-9 w-9 rounded-lg object-contain">
            @else
                <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary text-white shadow-sm">
                    <span class="text-sm font-bold">{{ strtoupper(substr($appBrand['companyName'], 0, 1)) }}</span>
                </div>
            @endif
            <span class="truncate text-base font-bold tracking-tight text-ink" :class="collapsed ? 'lg:hidden' : ''">{{ $appBrand['companyName'] }}</span>
        </a>
        <button type="button" class="btn-ghost btn-icon ml-auto lg:hidden" @click="drawerOpen = false" aria-label="Close menu">
            <x-icon name="x" class="size-5" />
        </button>
    </div>

    {{-- Navigation --}}
    <nav class="flex-1 overflow-y-auto px-3 py-4" :class="collapsed ? 'lg:px-2' : ''">
        <ul class="space-y-1">
            @foreach ($navItems as $nav)
                @if ($nav['visible'])
                    @if (! empty($nav['item']['group']))
                        {{-- Grouped accordion --}}
                        <li
                            class="mt-5 first:mt-0"
                            x-data="{ groupOpen: {{ collect($nav['children'])->contains(fn ($c) => $isActive($c['route'])) ? 'true' : 'false' }} }"
                        >
                            <button
                                type="button"
                                @click="groupOpen = !groupOpen"
                                :class="collapsed ? 'lg:justify-center lg:px-0' : ''"
                                class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-xs font-semibold tracking-wide text-ink-faint uppercase transition-colors hover:text-ink"
                            >
                                <span class="truncate" :class="collapsed ? 'lg:hidden' : ''">{{ $nav['item']['group'] }}</span>
                                <x-icon name="chevron-down" class="size-4 shrink-0 transition-transform" ::class="groupOpen && 'rotate-180'" />
                            </button>
                            <ul
                                x-bind:class="{ 'hidden': !groupOpen }"
                                class="mt-1 space-y-0.5 transition-all duration-200"
                                x-cloak
                            >
                                @foreach ($nav['children'] as $child)
                                    <li>
                                        <a
                                            href="{{ route($child['route']) }}"
                                            @click="drawerOpen = false"
                                            :class="collapsed ? 'lg:justify-center lg:px-0' : ''"
                                            class="{{ $isActive($child['route']) ? 'bg-primary/10 text-primary' : 'text-ink-soft hover:bg-surface-muted hover:text-ink' }} flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors"
                                        >
                                            <x-icon :name="$child['icon']" class="size-5 shrink-0" />
                                            <span class="truncate" :class="collapsed ? 'lg:hidden' : ''">{{ $child['label'] }}</span>
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        </li>
                    @else
                        {{-- Top-level link --}}
                        <li>
                            <a
                                href="{{ route($nav['item']['route']) }}"
                                @click="drawerOpen = false"
                                :class="collapsed ? 'lg:justify-center lg:px-0' : ''"
                                class="{{ $isActive($nav['item']['route']) ? 'bg-primary/10 text-primary' : 'text-ink-soft hover:bg-surface-muted hover:text-ink' }} flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors"
                            >
                                <x-icon :name="$nav['item']['icon']" class="size-5 shrink-0" />
                                <span class="truncate" :class="collapsed ? 'lg:hidden' : ''">{{ $nav['item']['label'] }}</span>
                            </a>
                        </li>
                    @endif
                @endif
            @endforeach
        </ul>
    </nav>

    {{-- Footer: collapse toggle + user --}}
    <div class="border-t border-line p-3">
        <button
            type="button"
            @click="collapsed = !collapsed"
            class="hidden w-full items-center justify-center gap-2 rounded-lg px-3 py-2 text-sm text-ink-faint transition-colors hover:bg-surface-muted hover:text-ink lg:flex"
        >
            <x-icon name="chevron-left" class="size-4" ::class="collapsed && 'rotate-180'" />
            <span :class="collapsed ? 'lg:hidden' : ''">Collapse</span>
        </button>

        <a href="{{ route('profile.edit') }}" class="mt-1 flex items-center gap-3 rounded-lg px-3 py-2 transition-colors hover:bg-surface-muted" :class="collapsed ? 'lg:justify-center lg:px-0' : ''">
            <div class="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary/15 text-xs font-bold text-primary">
                {{ auth()->user()->initials() }}
            </div>
            <div class="min-w-0" :class="collapsed ? 'lg:hidden' : ''">
                <p class="truncate text-sm font-semibold text-ink">{{ auth()->user()->displayName() }}</p>
                <p class="truncate text-xs text-ink-faint">{{ auth()->user()->getRoleNames()->first() }}</p>
            </div>
        </a>
    </div>
</aside>
