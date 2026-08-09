<x-settings-layout page-title="Roles & Permissions">
    <x-page-header title="Roles & Permissions" description="Predefined roles and the granular permission matrix." icon="roles">
        <x-slot name="actions">
            @if (auth()->user()->can('settings.roles.manage'))
                <x-button href="{{ route('settings.roles.create') }}" icon="plus">New role</x-button>
            @endif
        </x-slot>
    </x-page-header>

    <div class="mt-6">
        <x-alert type="info" class="mb-5">
            Permissions are grouped by <span class="font-semibold">module → feature → action</span>. Edit any role (or create custom ones) to reassign exactly what each role can view, create, edit, delete and export — no code changes required.
        </x-alert>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($roles as $role)
                <div class="surface-card flex flex-col p-5">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <div class="flex size-10 shrink-0 items-center justify-center rounded-lg {{ $role->name === 'Super Admin' ? 'bg-amber-100 text-amber-600 dark:bg-amber-500/15 dark:text-amber-400' : 'bg-primary/10 text-primary' }}">
                                <x-icon name="shield" class="size-5" />
                            </div>
                            <div class="min-w-0">
                                <p class="flex items-center gap-2 text-sm font-semibold text-ink">
                                    {{ $role->label ?: $role->name }}
                                    @if ($role->is_system)
                                        <x-badge color="neutral">System</x-badge>
                                    @endif
                                </p>
                                <p class="text-xs text-ink-faint">
                                    {{ $role->users_count }} user{{ $role->users_count === 1 ? '' : 's' }} · {{ $role->permissions_count }} permission{{ $role->permissions_count === 1 ? '' : 's' }}
                                </p>
                            </div>
                        </div>

                        @if (auth()->user()->can('settings.roles.manage') && $role->name !== 'Super Admin')
                            <div class="flex items-center gap-1">
                                <a href="{{ route('settings.roles.edit', $role) }}" class="btn-ghost btn-icon btn-sm" title="Edit role & permissions">
                                    <x-icon name="edit" class="size-4" />
                                </a>
                                @if (! $role->is_system && $role->users_count === 0)
                                    <form method="POST" action="{{ route('settings.roles.destroy', $role) }}"
                                        onsubmit="return confirm('Delete role {{ $role->name }}?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn-ghost btn-icon btn-sm text-rose-500" title="Delete role">
                                            <x-icon name="trash" class="size-4" />
                                        </button>
                                    </form>
                                @endif
                            </div>
                        @endif
                    </div>

                    <p class="mt-3 flex-1 text-sm text-ink-soft">{{ $role->description }}</p>

                    @if ($role->name === 'Super Admin')
                        <div class="mt-4">
                            <x-badge color="warning" dot>Unrestricted access</x-badge>
                        </div>
                    @else
                        <div class="mt-4 flex flex-wrap gap-1">
                            @foreach ($role->permissions->take(4) as $permission)
                                <x-badge color="neutral">{{ $permission->name }}</x-badge>
                            @endforeach
                            @if ($role->permissions_count > 4)
                                <x-badge color="neutral">+{{ $role->permissions_count - 4 }} more</x-badge>
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
</x-settings-layout>
