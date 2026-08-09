@props([
    'title' => null,
    'description' => null,
])

<div class="mb-6">
    @if ($title)
        <h2 class="text-sm font-semibold text-ink">{{ $title }}</h2>
    @endif
    @if ($description)
        <p class="mt-1 text-xs text-ink-faint">{{ $description }}</p>
    @endif
</div>
