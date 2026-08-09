@props([
    'icon' => 'inbox',
    'title' => 'Nothing here yet',
    'description' => null,
    'action' => null,
    'actionHref' => null,
    'actionIcon' => 'plus',
])

<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center px-6 py-16 text-center']) }}>
    <div class="flex size-16 items-center justify-center rounded-2xl bg-surface-muted text-ink-faint">
        <x-icon :name="$icon" class="size-8" />
    </div>
    <h3 class="mt-4 text-sm font-semibold text-ink">{{ $title }}</h3>
    @if ($description)
        <p class="mt-1 max-w-sm text-sm text-ink-faint">{{ $description }}</p>
    @endif
    @if ($action)
        <div class="mt-5">
            <x-button :href="$actionHref" :icon="$actionIcon" size="sm">{{ $action }}</x-button>
        </div>
    @endif
</div>
