<x-settings-layout page-title="Users">
    <x-page-header title="Users" description="Manage user accounts and their role assignments." icon="users">
        <x-slot name="actions">
            @if (auth()->user()->can('settings.users.manage'))
                <x-button href="{{ route('settings.users.create') }}" icon="plus">New user</x-button>
            @endif
        </x-slot>
    </x-page-header>

    <div class="mt-6">
        <x-card :padding="false">
            <form method="GET" action="{{ route('settings.users.index') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
                <div class="min-w-[220px] flex-1">
                    <x-input name="search" label="Search" placeholder="Name or email…" leadingIcon="search"
                        value="{{ request('search') }}" size="sm" />
                </div>
                <div class="w-44">
                    <x-select name="role" label="Role" size="sm">
                        <option value="">All roles</option>
                        @foreach ($roles as $role)
                            <option value="{{ $role->name }}" @selected(request('role') === $role->name)>{{ $role->name }}</option>
                        @endforeach
                    </x-select>
                </div>
                <div class="w-40">
                    <x-select name="status" label="Status" size="sm">
                        <option value="">Any status</option>
                        <option value="active" @selected(request('status') === 'active')>Active</option>
                        <option value="inactive" @selected(request('status') === 'inactive')>Inactive</option>
                    </x-select>
                </div>
                <div class="flex gap-2">
                    <x-button type="submit" size="sm" icon="filter">Filter</x-button>
                    @if (request()->hasAny(['search', 'role', 'status']))
                        <x-button href="{{ route('settings.users.index') }}" variant="ghost" size="sm">Clear</x-button>
                    @endif
                </div>
            </form>

            @if ($users->isEmpty())
                <x-empty-state icon="users" title="No users found" description="Try adjusting your filters, or create a new user." />
            @else
                <div class="table-wrap !border-0 !rounded-none !border-b-0">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Last login</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($users as $user)
                                <tr>
                                    <td>
                                        <div class="flex items-center gap-3">
                                            <div class="flex size-9 shrink-0 items-center justify-center rounded-full bg-primary/15 text-xs font-bold text-primary">
                                                {{ $user->initials() }}
                                            </div>
                                            <div class="min-w-0">
                                                <p class="font-medium text-ink">{{ $user->displayName() }}</p>
                                                <p class="text-xs text-ink-faint">{{ $user->email }}</p>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="flex flex-wrap gap-1">
                                            @foreach ($user->roles as $role)
                                                <x-badge color="primary">{{ $role->name }}</x-badge>
                                            @endforeach
                                        </div>
                                    </td>
                                    <td>
                                        @if ($user->is_active)
                                            <x-badge color="success" dot>Active</x-badge>
                                        @else
                                            <x-badge color="danger" dot>Inactive</x-badge>
                                        @endif
                                        @if ($user->access_until)
                                            <span class="ml-1 text-xs text-ink-faint" title="Access expires {{ $user->access_until->format('M d, Y') }}">
                                                until {{ $user->access_until->format('M d, Y') }}
                                            </span>
                                        @endif
                                    </td>
                                    <td class="text-ink-soft">
                                        {{ $user->last_login_at ? $user->last_login_at->diffForHumans() : 'Never' }}
                                    </td>
                                    <td class="text-right">
                                        @if (auth()->user()->can('settings.users.manage'))
                                            <div class="flex items-center justify-end gap-1">
                                                <a href="{{ route('settings.users.edit', $user) }}" class="btn-ghost btn-icon btn-sm" title="Edit">
                                                    <x-icon name="edit" class="size-4" />
                                                </a>
                                                @if (! $user->is(auth()->user()) && ! $user->isSuperAdmin())
                                                    <form method="POST" action="{{ route('settings.users.destroy', $user) }}"
                                                        onsubmit="return confirm('Deactivate {{ $user->name }}? Their record is kept and can be restored.');">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="btn-ghost btn-icon btn-sm text-rose-500" title="Deactivate">
                                                            <x-icon name="trash" class="size-4" />
                                                        </button>
                                                    </form>
                                                @endif
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($users->hasPages())
                <div class="px-5 py-4">
                    {{ $users->links() }}
                </div>
            @endif
        </x-card>
    </div>
</x-settings-layout>
