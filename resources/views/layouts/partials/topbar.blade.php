<header class="flex h-16 shrink-0 items-center gap-3 border-b border-line bg-surface px-4 sm:px-6 lg:px-8">
    {{-- Mobile menu button --}}
    <button type="button" class="btn-ghost btn-icon lg:hidden" @click="drawerOpen = true" aria-label="Open menu">
        <x-icon name="menu" class="size-6" />
    </button>

    {{-- Search --}}
    <div class="relative hidden max-w-md flex-1 md:block">
        <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-ink-faint">
            <x-icon name="search" class="size-4" />
        </div>
        <input
            type="search"
            placeholder="Search…"
            class="input !pl-9"
            x-data="{ q: '' }"
            x-on:keydown.prevent.cmd.k="$refs.searchInput.focus()"
        >
        <kbd class="pointer-events-none absolute inset-y-0 right-2 my-auto hidden h-5 items-center rounded border border-line bg-surface-muted px-1.5 text-[10px] font-medium text-ink-faint lg:flex">
            Ctrl K
        </kbd>
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
                    <span class="absolute right-2 top-2 flex size-2">
                        <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-primary opacity-75"></span>
                        <span class="relative inline-flex size-2 rounded-full bg-primary"></span>
                    </span>
                </button>
            @endslot

            <div class="px-3 py-2">
                <p class="text-sm font-semibold text-ink">Notifications</p>
                <p class="text-xs text-ink-faint">You're all caught up.</p>
            </div>
            <div class="mx-2 my-1 divider"></div>
            <p class="px-3 py-2 text-center text-xs text-ink-faint">No new notifications</p>
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
