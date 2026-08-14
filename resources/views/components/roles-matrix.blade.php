@props(['groups' => [], 'selected' => []])

<div x-data="permissionMatrix()">
    <div class="mb-4 flex flex-wrap items-center gap-2">
        <x-button size="sm" variant="secondary" type="button" @click="checkAllPermissions(true)" icon="check">Select all</x-button>
        <x-button size="sm" variant="ghost" type="button" @click="checkAllPermissions(false)">Clear all</x-button>
        <p class="text-xs text-ink-faint">Changes apply when you save the role.</p>
    </div>

    @foreach ($groups as $moduleKey => $features)
        @php
            $def = config('permissions.modules.'.$moduleKey, []);
        @endphp
        @continue(! in_array($moduleKey, $enabledModuleKeys ?? [], true))
        <div class="surface mb-4 overflow-hidden" x-data="{ open: true }">
            <button type="button" @click="open = !open" class="flex w-full items-center gap-3 px-5 py-4 text-left">
                <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <x-icon :name="$def['icon'] ?? 'modules'" class="size-5" />
                </div>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-ink">{{ $def['label'] ?? ucfirst($moduleKey) }}</p>
                    <p class="truncate text-xs text-ink-faint">{{ $def['description'] ?? '' }}</p>
                </div>
                <span class="flex shrink-0 items-center gap-3">
                    <label class="flex cursor-pointer items-center gap-1.5 rounded-lg border border-line px-2.5 py-1.5 text-xs font-medium text-ink-soft hover:bg-surface-muted" @click.stop>
                        <input type="checkbox" @change="toggleModule('{{ $moduleKey }}', $event.target.checked)" class="size-3.5 rounded border-line text-primary focus:ring-primary">
                        All
                    </label>
                    <x-icon name="chevron-down" class="size-4 text-ink-faint transition-transform" x-bind:class="open ? '' : 'rotate-180'" />
                </span>
            </button>

            <div x-show="open" x-collapse>
                <div class="space-y-2 border-t border-line p-4">
                    @foreach ($features as $feature => $actions)
                        <div class="flex flex-col gap-2 rounded-lg bg-surface-muted/60 p-3 sm:flex-row sm:items-center sm:gap-4">
                            <span class="w-44 shrink-0 text-sm font-medium capitalize text-ink">{{ str_replace('_', ' ', $feature) }}</span>
                            <div class="flex flex-wrap items-center gap-x-5 gap-y-2">
                                @foreach ($actions as $action => $permKey)
                                    <label class="flex cursor-pointer items-center gap-1.5 text-sm text-ink-soft hover:text-ink">
                                        <input type="checkbox" name="permissions[]" value="{{ $permKey }}" data-perm-module="{{ $moduleKey }}"
                                            @checked(in_array($permKey, $selected))
                                            class="size-4 rounded border-line text-primary focus:ring-primary">
                                        <span class="capitalize">{{ str_replace('_', ' ', $action) }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endforeach
</div>
