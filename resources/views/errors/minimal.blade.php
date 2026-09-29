@php
    $status = (int) ($code ?? (($exception ?? null)?->getCode() ?: 500));
    $titles = [
        403 => ['Access denied', 'You do not have permission to access this page.', 'shield'],
        404 => ['Page not found', 'The page or resource you requested could not be found.', 'search'],
        419 => ['Session expired', 'Your session has expired. Please sign in and try again.', 'lock'],
        422 => ['Check your input', 'Some information needs your attention before you can continue.', 'warning'],
        500 => ['Something went wrong', 'The application could not complete this request. Please try again.', 'warning'],
        503 => ['Service unavailable', 'The application is temporarily unavailable. Please try again shortly.', 'refresh'],
    ];
    [$heading, $message, $icon] = $titles[$status] ?? $titles[500];
@endphp

<x-guest-layout :page-title="$heading">
    <div class="text-center">
        <div class="mx-auto flex size-16 items-center justify-center rounded-2xl bg-primary/10 text-primary">
            <x-icon :name="$icon" class="size-8" />
        </div>
        <p class="mt-6 text-sm font-semibold text-primary">Error {{ $status }}</p>
        <h1 class="mt-2 text-2xl font-bold tracking-tight text-ink">{{ $heading }}</h1>
        <p class="mt-2 text-sm leading-6 text-ink-soft">{{ $message }}</p>
        <div class="mt-6 flex flex-col gap-2 sm:flex-row sm:justify-center">
            <a href="{{ url('/') }}" class="btn-primary">Go to home</a>
            <button type="button" onclick="history.back()" class="btn-secondary">Go back</button>
        </div>
    </div>
</x-guest-layout>
