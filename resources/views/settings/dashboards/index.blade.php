<x-settings-layout page-title="Dashboard layouts">
    <x-page-header title="Dashboard layouts" description="Choose the widgets each role sees on their ERP home page." icon="dashboard">
        <x-slot name="actions">
            <x-button href="{{ route('dashboard') }}" variant="secondary" icon="arrow-left">Back to dashboard</x-button>
        </x-slot>
    </x-page-header>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-[240px_minmax(0,1fr)]">
        <x-card title="Roles" description="Select a role to configure." :padding="false">
            <nav class="divide-y divide-line">
                @foreach ($roles as $item)
                    <a href="{{ route('settings.dashboards.edit', $item) }}" class="flex items-center gap-3 px-5 py-3.5 transition hover:bg-primary/5 {{ $item->id === $role->id ? 'bg-primary/5' : '' }}">
                        <span class="flex size-8 items-center justify-center rounded-lg {{ $item->id === $role->id ? 'bg-primary text-white' : 'bg-surface-muted text-ink-soft' }}"><x-icon name="shield" class="size-4" /></span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-semibold text-ink">{{ $item->label ?: $item->name }}</span>
                            <span class="block text-xs text-ink-faint">{{ $item->users_count ?? $item->users()->count() }} users</span>
                        </span>
                        @if ($item->id === $role->id)<x-icon name="chevron-right" class="size-4 text-primary" />@endif
                    </a>
                @endforeach
            </nav>
        </x-card>

        <x-card title="{{ $role->label ?: $role->name }} dashboard" description="Widgets are always checked against the role's current permissions before they are shown.">
            <form method="POST" action="{{ route('settings.dashboards.update', $role) }}" class="space-y-6">
                @csrf
                <div class="flex items-start justify-between gap-4 rounded-xl border border-primary/15 bg-primary/5 p-4">
                    <div>
                        <p class="text-sm font-semibold text-ink">Permission-aware layout</p>
                        <p class="mt-1 text-xs leading-5 text-ink-soft">Select the widgets this role should see. A widget disappears automatically if the role no longer has its required permission.</p>
                    </div>
                    <x-icon name="shield" class="size-5 shrink-0 text-primary" />
                </div>

                <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                    @foreach ($definitions as $key => $definition)
                        @php $isVisible = ! $preferences->isNotEmpty() || $preferences->get($key)?->is_visible; @endphp
                        <label class="group flex cursor-pointer items-start gap-3 rounded-xl border p-4 transition {{ $isVisible ? 'border-primary/30 bg-primary/5' : 'border-line bg-surface hover:border-primary/20' }}">
                            <input type="checkbox" name="widgets[]" value="{{ $key }}" @checked($isVisible) class="mt-1 size-4 rounded border-line text-primary focus:ring-primary/30">
                            <span class="min-w-0 flex-1">
                                <span class="flex items-center gap-2 text-sm font-semibold text-ink"><x-icon :name="$definition['icon']" class="size-4 text-primary" />{{ $definition['label'] }}</span>
                                <span class="mt-1 block text-xs leading-5 text-ink-soft">{{ $definition['description'] }}</span>
                                <span class="mt-2 inline-flex rounded-full bg-surface-muted px-2 py-0.5 text-[11px] font-medium text-ink-faint">{{ $definition['section'] }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>

                <div class="flex justify-end border-t border-line pt-5">
                    <x-button type="submit" icon="save">Save {{ $role->label ?: $role->name }} layout</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-settings-layout>
