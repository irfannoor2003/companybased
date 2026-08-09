@props([
    'title' => null,
    'description' => null,
    'icon' => null,
])

<div class="flex flex-wrap items-end justify-between gap-4">
    <div class="flex min-w-0 items-start gap-3">
        @if ($icon)
            <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                <x-icon :name="$icon" class="size-5" />
            </div>
        @endif
        <div class="min-w-0">
            @if ($title)
                <h1 class="text-xl font-bold tracking-tight text-ink sm:text-2xl">{{ $title }}</h1>
            @endif
            @if ($description)
                <p class="mt-1 text-sm text-ink-faint">{{ $description }}</p>
            @endif
        </div>
    </div>

    @isset($actions)
        <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
    @endisset
</div>
