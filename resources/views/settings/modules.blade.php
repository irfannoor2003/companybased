<x-settings-layout page-title="Modules">
    <x-page-header title="Modules" description="Enable or disable modules for this company's deployment." icon="modules" />

    <div class="mt-6">
        <x-alert type="info" class="mb-5">
            Core modules (<span class="font-semibold">Dashboard, Reports, Settings</span>) are always enabled and cannot be turned off. Disabled modules are hidden from navigation and their routes return <span class="font-semibold">404</span>.
        </x-alert>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($modules as $module)
                <div class="surface-card p-5 {{ $module->is_core ? 'opacity-90' : '' }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <div class="flex size-10 shrink-0 items-center justify-center rounded-lg {{ $module->enabled ? 'bg-primary/10 text-primary' : 'bg-surface-muted text-ink-faint' }}">
                                <x-icon :name="$module->icon" class="size-5" />
                            </div>
                            <div class="min-w-0">
                                <p class="flex items-center gap-2 text-sm font-semibold text-ink">
                                    {{ $module->label }}
                                    @if ($module->is_core)
                                        <x-badge color="info">Core</x-badge>
                                    @endif
                                </p>
                                <p class="text-xs text-ink-faint">{{ $module->key }}</p>
                            </div>
                        </div>

                        @if ($module->is_core)
                            <span class="relative inline-flex h-6 w-11 cursor-not-allowed items-center rounded-full bg-primary opacity-60" title="Core module — always enabled">
                                <span class="inline-block size-4 translate-x-6 transform rounded-full bg-white shadow"></span>
                            </span>
                        @else
                            <form method="POST" action="{{ route('settings.modules.update', $module) }}" x-data="{ enabled: {{ $module->enabled ? 'true' : 'false' }} }">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="enabled" :value="enabled ? '1' : '0'">
                                <button
                                    type="submit"
                                    @click="enabled = !enabled"
                                    class="relative inline-flex h-6 w-11 cursor-pointer items-center rounded-full transition-colors duration-200"
                                    :class="enabled ? 'bg-primary' : 'bg-ink-faint/40'"
                                    :aria-checked="enabled ? 'true' : 'false'"
                                    role="switch"
                                    :title="enabled ? 'Click to disable' : 'Click to enable'"
                                >
                                    <span class="inline-block size-4 transform rounded-full bg-white shadow transition-transform duration-200" :class="enabled ? 'translate-x-6' : 'translate-x-1'"></span>
                                </button>
                            </form>
                        @endif
                    </div>

                    <p class="mt-3 text-sm text-ink-soft">{{ $module->description }}</p>
                </div>
            @endforeach
        </div>
    </div>
</x-settings-layout>
