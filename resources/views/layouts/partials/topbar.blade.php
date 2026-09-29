@php
    $unreadNotifications = auth()->user()->unreadNotifications()->latest()->limit(5)->get();
    $unreadCount = auth()->user()->unreadNotifications()->count();
@endphp

<header class="flex h-16 shrink-0 items-center gap-3 border-b border-line bg-surface px-4 sm:px-6 lg:px-8">
    {{-- Mobile menu button --}}
    <button type="button" class="btn-ghost btn-icon lg:hidden" @click="drawerOpen = true" aria-label="Open menu">
        <x-icon name="menu" class="size-6" />
    </button>

    {{-- Global search. Results are permission-scoped server-side by SearchRegistry. --}}
    <div
        class="relative hidden w-full max-w-md flex-1 md:block"
        x-data="globalSearch({ endpoint: '{{ route('search.api') }}', resultsUrl: '{{ route('search') }}' })"
        x-on:keydown.escape="open = false"
        x-on:click.outside="open = false"
        x-on:keydown.window.meta.k.prevent="focusInput()"
        x-on:keydown.window.ctrl.k.prevent="focusInput()"
    >
        <div class="pointer-events-none absolute inset-y-0 left-0 z-10 flex items-center pl-3 text-ink-faint">
            <x-icon name="search" class="size-4" />
        </div>
        <input
            x-ref="searchInput"
            type="search"
            placeholder="Search customers, orders, SKUs…"
            aria-label="Global search"
            autocomplete="off"
            class="input !pl-9"
            x-model="q"
            x-on:focus="open = true"
            x-on:keydown.enter.prevent="submit()"
        >
        <kbd class="pointer-events-none absolute inset-y-0 right-2 my-auto hidden h-5 items-center rounded border border-line bg-surface-muted px-1.5 text-[10px] font-medium text-ink-faint lg:flex">
            Ctrl K
        </kbd>

        {{-- Results dropdown --}}
        <div
            x-cloak
            x-show="open"
            class="absolute left-0 right-0 top-full z-50 mt-2 max-h-[70vh] overflow-y-auto rounded-xl border border-line bg-surface shadow-xl"
        >
            <template x-if="loading">
                <p class="px-4 py-6 text-center text-sm text-ink-faint">Searching…</p>
            </template>

            <template x-if="! loading && q.trim().length >= 2 && results.length === 0">
                <p class="px-4 py-6 text-center text-sm text-ink-soft">No matches for “<span x-text="q.trim()"></span>”</p>
            </template>

            <template x-if="! loading && q.trim().length < 2">
                <p class="px-4 py-6 text-center text-sm text-ink-faint">Type at least two characters.</p>
            </template>

            <ul class="py-1" x-show="! loading && results.length > 0" role="listbox">
                <template x-for="result in results" :key="result.entity + '-' + result.title">
                    <li>
                        <a
                            :href="result.url || '#'"
                            class="flex items-center gap-3 px-4 py-2.5 transition hover:bg-surface-muted"
                            @click.prevent="select(result)"
                            role="option"
                        >
                            <div class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                <x-icon name="document" class="size-4" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-semibold text-ink" x-html="highlight(result.title)"></p>
                                <p class="truncate text-xs text-ink-soft">
                                    <span x-text="result.type"></span>
                                    <template x-if="result.subtitle">
                                        <span> · <span x-text="result.subtitle"></span></span>
                                    </template>
                                </p>
                            </div>
                            <template x-if="result.meta">
                                <span class="shrink-0 rounded-md bg-surface-muted px-2 py-1 text-xs font-medium text-ink-soft" x-text="result.meta"></span>
                            </template>
                        </a>
                    </li>
                </template>
            </ul>

            {{-- "view all" footer --}}
            <div class="border-t border-line px-4 py-2" x-show="! loading && results.length > 0">
                <button type="button" class="text-xs font-medium text-primary hover:text-primary-strong" @click="submit()">
                    View all results
                </button>
            </div>
        </div>
    </div>

    <div class="ml-auto flex items-center gap-1.5">
        {{-- Theme toggle --}}
        <button
            type="button"
            class="btn-ghost btn-icon"
            @click="toggleTheme()"
            :title="isDark ? 'Switch to light mode' : 'Switch to dark mode'"
            aria-label="Toggle theme"
        >
            <x-icon name="moon" class="size-5" x-show="!isDark" />
            <x-icon name="sun" class="size-5" x-show="isDark" />
        </button>

        {{-- Notifications --}}
        <x-dropdown align="right" class="!mr-1">
            @slot('trigger')
                <button type="button" class="btn-ghost btn-icon relative" aria-label="Notifications">
                    <x-icon name="bell" class="size-5" />
                    @if ($unreadCount > 0)
                        <span class="absolute -right-0.5 -top-0.5 flex min-w-4 items-center justify-center rounded-full bg-primary px-1 text-[10px] font-bold leading-4 text-white">{{ $unreadCount > 9 ? '9+' : $unreadCount }}</span>
                    @endif
                </button>
            @endslot

            <div class="flex items-center justify-between px-3 py-2">
                <p class="text-sm font-semibold text-ink">Notifications</p>
                @if ($unreadCount > 0)
                    <form method="POST" action="{{ route('notifications.read-all') }}">
                        @csrf
                        <button type="submit" class="text-xs font-medium text-primary hover:text-primary-strong">Mark all read</button>
                    </form>
                @endif
            </div>
            <div class="mx-2 my-1 divider"></div>
            @forelse ($unreadNotifications as $notification)
                <form method="POST" action="{{ route('notifications.read', $notification->id) }}">
                    @csrf
                    <button type="submit" class="block w-full px-3 py-2 text-left transition hover:bg-surface-muted">
                        <p class="text-sm font-semibold text-ink">{{ data_get($notification->data, 'title', 'Notification') }}</p>
                        <p class="mt-0.5 text-xs text-ink-soft">{{ data_get($notification->data, 'message', '') }}</p>
                        <p class="mt-1 text-[11px] text-ink-faint">{{ $notification->created_at?->diffForHumans() }}</p>
                    </button>
                </form>
            @empty
                <p class="px-3 py-2 text-center text-xs text-ink-faint">No new notifications</p>
            @endforelse
        </x-dropdown>

        {{-- User menu --}}
        <x-dropdown align="right">
            @slot('trigger')
                <button type="button" class="flex items-center gap-2 rounded-lg p-1.5 transition-colors hover:bg-surface-muted">
                    <div class="flex size-8 items-center justify-center rounded-full bg-primary/15 text-xs font-bold text-primary">
                        {{ auth()->user()->initials() }}
                    </div>
                    <span class="hidden text-sm font-medium text-ink sm:block">{{ auth()->user()->displayName() }}</span>
                    <x-icon name="chevron-down" class="hidden size-4 text-ink-faint sm:block" />
                </button>
            @endslot

            <a href="{{ route('profile.edit') }}" class="flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm text-ink-soft transition-colors hover:bg-surface-muted hover:text-ink">
                <x-icon name="user" class="size-4" />
                Profile
            </a>

            @if (auth()->user()->can('settings.company.view'))
                <a href="{{ route('settings.company') }}" class="flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm text-ink-soft transition-colors hover:bg-surface-muted hover:text-ink">
                    <x-icon name="settings" class="size-4" />
                    Settings
                </a>
            @endif

            <div class="mx-2 my-1 divider"></div>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-sm text-rose-600 transition-colors hover:bg-rose-50 dark:text-rose-400 dark:hover:bg-rose-500/10">
                    <x-icon name="logout" class="size-4" />
                    Sign out
                </button>
            </form>
        </x-dropdown>
    </div>
</header>
