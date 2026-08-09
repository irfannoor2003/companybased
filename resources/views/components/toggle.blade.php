@props([
    'name' => null,
    'checked' => false,
    'label' => null,
    'description' => null,
    'value' => '1',
])

<label class="flex cursor-pointer items-start gap-3 select-none">
    <div class="relative mt-0.5 inline-flex shrink-0">
        <input
            type="checkbox"
            name="{{ $name }}"
            value="{{ $value }}"
            {{ $checked ? 'checked' : '' }}
            @change=""
            {{ $attributes->merge(['class' => 'peer sr-only']) }}
        >
        <div class="h-6 w-11 rounded-full bg-ink-faint/40 transition-colors peer-checked:bg-primary peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-primary peer-disabled:cursor-not-allowed peer-disabled:opacity-50"></div>
        <div class="pointer-events-none absolute left-0.5 top-0.5 size-5 rounded-full bg-white shadow transition-transform peer-checked:translate-x-5"></div>
    </div>

    @if ($label || $description)
        <div class="min-w-0">
            @if ($label)
                <span class="block text-sm font-medium text-ink">{{ $label }}</span>
            @endif
            @if ($description)
                <span class="mt-0.5 block text-xs text-ink-faint">{{ $description }}</span>
            @endif
        </div>
    @endif
</label>
