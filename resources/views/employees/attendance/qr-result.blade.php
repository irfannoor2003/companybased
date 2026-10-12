<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $ok ? 'Clocked in' : 'Attendance' }} - {{ $appBrand['companyName'] }}</title>

    {{--
        This page used to load the Tailwind CDN and hand-write slate colours.

        Two consequences, both real: `bg-surface` is a design-system token the CDN
        build knows nothing about, so the card rendered with NO background at all;
        and with no theme script it stayed light on a dark-mode phone, so an
        employee scanning the QR code got a white flash on the kiosk. It now uses
        the same compiled stylesheet and tokens as every other screen.
    --}}
    @vite(['resources/css/app.css'])

    @include('partials.theme-tokens')

    <script>
        (function () {
            var stored = localStorage.getItem('cb-theme');
            var serverDefault = '{{ $appBrand['darkMode'] }}';
            var pref = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            var theme = stored === 'dark' || stored === 'light'
                ? stored
                : (serverDefault === 'dark' || serverDefault === 'light' ? serverDefault : pref);
            if (theme === 'dark') document.documentElement.classList.add('dark');
            window.__cbTheme = theme;
        })();
    </script>
</head>
<body class="flex min-h-screen items-center justify-center bg-canvas p-6">
    <div class="w-full max-w-sm rounded-2xl border border-line bg-surface p-8 text-center shadow-card">
        <div class="mx-auto flex size-16 items-center justify-center rounded-full {{ $ok
            ? 'bg-emerald-500/15 text-emerald-500 dark:text-emerald-400'
            : 'bg-rose-500/15 text-rose-500 dark:text-rose-400' }}">
            @if ($ok)
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                </svg>
            @else
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            @endif
        </div>

        <h1 class="mt-4 text-xl font-bold text-ink">{{ $message }}</h1>

        @if (! empty($name))
            <p class="mt-2 text-sm text-ink-soft">{{ $name }}</p>
        @endif

        @if (! empty($time))
            <p class="mt-1 font-mono text-sm text-ink-faint">{{ $time }}</p>
        @endif
    </div>
</body>
</html>
