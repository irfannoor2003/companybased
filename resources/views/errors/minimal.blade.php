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

    // A 403 raised as abort(403, '...') carries a message somebody wrote for the
    // user: 'The "Employees" module is currently disabled...', 'Core modules
    // cannot be disabled', 'Attendance is only available to employees on the
    // payroll'. This template used to discard all of them and show the generic
    // "You do not have permission to access this page" instead, which is both
    // wrong and actively unhelpful — it blamed the user's permissions for what
    // is a configuration state.
    //
    // Restricted to 403 on purpose. 403 is the only status this codebase aborts
    // with authored copy, it is never used to surface internals, and a
    // message-less abort (IncomingShipmentController) falls back to the default
    // above, as does the framework's own authorization failure.
    $authored = $status === 403 && isset($exception)
        ? trim((string) $exception->getMessage())
        : '';

    if ($authored !== '') {
        $message = $authored;
    }
@endphp

<x-guest-layout :page-title="$heading">
    <div class="text-center">
        <div class="mx-auto flex size-16 items-center justify-center rounded-2xl bg-primary/10 text-primary-ink">
            <x-icon :name="$icon" class="size-8" />
        </div>
        <p class="mt-6 text-sm font-semibold text-primary-ink">Error {{ $status }}</p>
        <h1 class="mt-2 text-2xl font-bold tracking-tight text-ink">{{ $heading }}</h1>
        <p class="mt-2 text-sm leading-6 text-ink-soft">{{ $message }}</p>
        <div class="mt-8 flex flex-col gap-3 sm:flex-row sm:justify-center">
            {{--
                The shared x-button component rather than raw .btn-primary /
                .btn-secondary classes: the component pairs each variant with a
                size and an icon, which is what keeps a hand-written button
                from drifting into an unpadded or iconless one. Plain onclick
                rather than Alpine, because the guest layout does not load it.
            --}}
            <x-button href="{{ url('/') }}" icon="dashboard">Go to home</x-button>
            <x-button variant="secondary" icon="arrow-left" onclick="history.back()">Go back</x-button>
        </div>

        @if (isset($exception) && app()->isLocal())
            {{-- Stack traces are only ever shown with debug on, and only on local. --}}
            @if (config('app.debug'))
                <details class="mt-8 text-left">
                    <summary class="cursor-pointer text-xs font-semibold text-ink-faint hover:text-ink-soft">
                        {{ $exception->getMessage() ?: 'Show error details' }}
                    </summary>
                    <pre class="mt-3 max-h-64 overflow-auto rounded-lg border border-line bg-surface-muted p-4 text-[11px] leading-5 text-ink-soft whitespace-pre-wrap">{{ $exception->getTraceAsString() }}</pre>
                </details>
            @endif
        @endif
    </div>
</x-guest-layout>
