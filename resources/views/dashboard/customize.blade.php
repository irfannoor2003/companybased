<x-app-layout page-title="Customize dashboard">
    <x-slot name="header">
        <x-page-header title="Customize your dashboard" description="Choose the information you want to see. Widgets are limited by your permissions and enabled modules." icon="dashboard">
            <x-slot name="actions"><x-button href="{{ route('dashboard') }}" variant="secondary" icon="arrow-left">Back to dashboard</x-button></x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 max-w-5xl">
        <x-card title="Your widgets" description="Your personal layout overrides the default layout for your role.">
            <form method="POST" action="{{ route('dashboard.customize.update') }}" class="space-y-6">
                @csrf
                @method('PUT')
                <div class="flex items-start justify-between gap-4 rounded-xl border border-primary/15 bg-primary/5 p-4">
                    <div>
                        <p class="text-sm font-semibold text-ink">Permission-aware dashboard</p>
                        <p class="mt-1 text-xs leading-5 text-ink-soft">You can only select widgets allowed by your current role permissions. An administrator can still change the role defaults for everyone.</p>
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
                    <x-button type="submit" icon="save">Save my dashboard</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
