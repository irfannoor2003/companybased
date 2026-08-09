@props([
    'label' => null,
    'required' => false,
    'hint' => null,
    'error' => null,
])

<div>
    @if ($label)
        <label class="label" for="{{ $attributes->get('id') ?? $attributes->get('name') }}">
            {{ $label }}
            @if ($required)
                <span class="text-rose-500">*</span>
            @endif
        </label>
    @endif

    <textarea
        {{ $attributes->merge([
            'class' => 'input min-h-[80px] resize-y' . ($error ? ' !border-rose-400 focus:!ring-rose-200' : ''),
            'id' => $attributes->get('id') ?? $attributes->get('name'),
        ]) }}
    >{{ $slot }}</textarea>

    @if ($hint && ! $error)
        <p class="mt-1.5 text-xs text-ink-faint">{{ $hint }}</p>
    @endif

    @if ($error)
        <p class="mt-1.5 text-xs text-rose-600 dark:text-rose-400">{{ $error }}</p>
    @endif
</div>
