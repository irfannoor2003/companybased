@props(['active'])

@php
$classes = ($active ?? false)
            ? 'block w-full ps-3 pe-4 py-2 border-l-4 border-primary text-start text-base font-medium text-primary-ink bg-primary/10 focus:outline-none focus:text-primary-ink focus:bg-primary/15 focus:border-primary transition duration-150 ease-in-out'
            : 'block w-full ps-3 pe-4 py-2 border-l-4 border-transparent text-start text-base font-medium text-ink-soft hover:text-ink hover:bg-surface-muted hover:border-line focus:outline-none focus:text-ink focus:bg-surface-muted focus:border-line transition duration-150 ease-in-out';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
