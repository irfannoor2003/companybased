@props([
    'label' => null,
    'required' => false,
    'hint' => null,
    'error' => null,
    'leadingIcon' => null,
    'trailingIcon' => null,
    'size' => 'md',
])

@php
    $base = $size === 'sm' ? '!py-1.5 !text-sm' : '';
    $leading = $leadingIcon ? 'pl-9' : '';
    $trailing = $trailingIcon ? 'pr-9' : '';
@endphp

<div {{ $wrapperAttributes ?? '' }}>
    @if ($label)
        <label class="label" for="{{ $attributes->get('id') ?? $attributes->get('name') }}">
            {{ $label }}
            @if ($required)
                <span class="text-rose-500">*</span>
            @endif
        </label>
    @endif

    <div class="relative">
        @if ($leadingIcon)
            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-ink-faint">
                <x-icon :name="$leadingIcon" class="size-4" />
            </div>
        @endif

        <input
            {{ $attributes->merge([
                'class' => trim("input {$base} {$leading} {$trailing}") . ($error ? ' !border-rose-400 focus:!ring-rose-200' : ''),
                'id' => $attributes->get('id') ?? $attributes->get('name'),
            ]) }}
        />

        @if ($trailingIcon)
            <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3 text-ink-faint">
                <x-icon :name="$trailingIcon" class="size-4" />
            </div>
        @endif
    </div>

    @if ($hint && ! $error)
        <p class="mt-1.5 text-xs text-ink-faint">{{ $hint }}</p>
    @endif

    @if ($error)
        <p class="mt-1.5 text-xs text-rose-600 dark:text-rose-400">{{ $error }}</p>
    @endif
</div>
