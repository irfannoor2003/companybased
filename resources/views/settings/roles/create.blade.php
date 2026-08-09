<x-settings-layout page-title="New role">
    <x-page-header title="New role" description="Create a custom role and assign its permissions." icon="roles">
        <x-slot name="actions">
            <x-button href="{{ route('settings.roles.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
        </x-slot>
    </x-page-header>

    <form method="POST" action="{{ route('settings.roles.store') }}" class="mt-6 space-y-6">
        @csrf

        <div class="max-w-2xl">
            <x-card title="Role details" description="The role name is used in code and permission checks.">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-input name="name" label="Role name" required value="{{ old('name') }}" hint="e.g. Warehouse Supervisor" />
                    <x-input name="label" label="Display label" value="{{ old('label') }}" hint="Optional — shown in the UI." />
                </div>
                <div class="mt-5">
                    <x-textarea name="description" label="Description">{{ old('description') }}</x-textarea>
                </div>
            </x-card>
        </div>

        <x-card title="Permissions" description="Tick the module → feature → action pairs this role may perform.">
            <x-roles-matrix :groups="$groups" :selected="old('permissions', [])" />
        </x-card>

        <div class="flex justify-end gap-3">
            <x-button href="{{ route('settings.roles.index') }}" variant="ghost">Cancel</x-button>
            <x-button type="submit" icon="check">Create role</x-button>
        </div>
    </form>
</x-settings-layout>
