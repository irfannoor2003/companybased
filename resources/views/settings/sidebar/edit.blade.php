<x-app-layout :pageTitle="'Sidebar — '.$role->name">
    <x-slot name="header">
        <x-page-header title="Sidebar — {{ $role->name }}" description="Set the default sidebar for this role." icon="menu">
            <x-slot name="actions">
                <x-button href="{{ route('settings.sidebar.index') }}" variant="ghost" icon="arrow-left">All roles</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 max-w-none space-y-6">
        <div class="flex flex-wrap gap-2">
            @foreach ($roles as $option)
                <a href="{{ route('settings.sidebar.edit', $option) }}"
                   class="rounded-lg border px-3 py-2 text-sm font-medium transition-colors {{ $option->id === $role->id ? 'border-primary bg-primary/10 text-primary-ink' : 'border-line text-ink-soft hover:bg-surface-muted' }}">
                    {{ $option->name }}
                </a>
            @endforeach
        </div>

        @include('sidebar.partials.editor')
    </div>
</x-app-layout>
