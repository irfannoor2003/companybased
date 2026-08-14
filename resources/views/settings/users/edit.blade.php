<x-settings-layout page-title="Edit user">
    <x-page-header title="Edit user" description="Update {{ $user->name }}'s account details and roles." icon="users">
        <x-slot name="actions">
            <x-button href="{{ route('settings.users.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
        </x-slot>
    </x-page-header>

    <div class="mt-6 max-w-3xl">
        <x-card title="Account details" description="The user signs in with this email and password.">
            <form method="POST" action="{{ route('settings.users.update', $user) }}" class="space-y-5">
                @csrf
                @method('PUT')

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-input name="first_name" label="First name" required value="{{ old('first_name', $user->first_name ?: $user->name) }}" />
                    <x-input name="last_name" label="Last name" value="{{ old('last_name', $user->last_name) }}" />
                    <x-input name="email" label="Email" type="email" required value="{{ old('email', $user->email) }}" />
                    <x-input name="phone" label="Phone" value="{{ old('phone', $user->phone) }}" />
                    <x-input name="password" label="New password" type="password" hint="Leave blank to keep the current password." />
                    <x-input name="password_confirmation" label="Confirm new password" type="password" />
                </div>

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div>
                        <span class="label">Account status</span>
                        <div class="flex items-center gap-6 rounded-lg border border-line px-3 py-2.5">
                            <label class="flex cursor-pointer items-center gap-2 text-sm text-ink">
                                <input type="radio" name="is_active" value="1" @checked(old('is_active', $user->is_active)) class="size-4 text-primary focus:ring-primary"> Active
                            </label>
                            <label class="flex cursor-pointer items-center gap-2 text-sm text-ink">
                                <input type="radio" name="is_active" value="0" @checked(old('is_active', $user->is_active) === false || old('is_active') === '0') class="size-4 text-primary focus:ring-primary"> Inactive
                            </label>
                        </div>
                    </div>
                    <x-input name="access_until" label="Access expires (optional)" type="date" hint="Use for time-boxed access, e.g. Auditor."
                        value="{{ old('access_until', $user->access_until?->format('Y-m-d')) }}" />
                </div>

                <div>
                    <span class="label">Roles <span class="text-rose-500">*</span></span>
                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        @foreach ($roles as $role)
                            <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-line px-3 py-2.5 transition-colors has-[:checked]:border-primary has-[:checked]:bg-primary/5">
                                <input type="checkbox" name="roles[]" value="{{ $role->id }}"
                                    @checked(in_array($role->id, old('roles', $user->roles->pluck('id')->all())))
                                    class="size-4 rounded border-line text-primary focus:ring-primary">
                                <span class="min-w-0">
                                    <span class="block text-sm font-medium text-ink">{{ $role->label ?: $role->name }}</span>
                                    <span class="block truncate text-xs text-ink-faint">{{ $role->description }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                    @error('roles')<p class="mt-1.5 text-xs text-rose-600">{{ $message }}</p>@enderror
                </div>

                <div class="flex justify-end gap-3 border-t border-line pt-4">
                    <x-button href="{{ route('settings.users.index') }}" variant="ghost">Cancel</x-button>
                    <x-button type="submit" icon="save">Save changes</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-settings-layout>
