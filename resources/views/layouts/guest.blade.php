<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ isset($pageTitle) ? $pageTitle.' — ' : '' }}{{ $appBrand['companyName'] }}</title>

        @if ($appBrand['favicon'])
            <link rel="icon" href="{{ Storage::url($appBrand['favicon']) }}">
        @endif

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <style>
            :root {
                --color-primary: {{ $appBrand['primaryRgb'] }};
                --color-primary-strong: {{ $appBrand['primaryStrongRgb'] }};
                --color-primary-ink: {{ $appBrand['primaryRgb'] }};
                --color-accent: {{ $appBrand['accentRgb'] }};
            }

            .dark {
                --color-primary-ink: {{ $appBrand['primaryInkRgb'] }};
            }
        </style>

        {{--
            Three-tier, matching layouts/app.blade.php exactly: an explicit choice,
            then the tenant's configured default, then the OS.

            This previously fell back straight to the OS, ignoring
            `branding.dark_mode`. An organisation set to dark therefore got a LIGHT
            login page and then a dark app the moment it signed in -- the theme
            visibly flipping with no user action. `window.__cbTheme` was also unset
            here, so anything reading it on a guest page got undefined.
        --}}
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
    <body class="flex min-h-full items-center justify-center bg-canvas px-4 py-10 font-sans antialiased sm:py-16">
        <div class="w-full max-w-md">
            <div class="mb-8 flex flex-col items-center text-center">
                <a href="{{ route('login') }}" class="flex items-center gap-3">
                    @if ($appBrand['logo'])
                        <img src="{{ Storage::url($appBrand['logo']) }}" alt="{{ $appBrand['companyName'] }}" class="h-11 w-11 rounded-xl object-contain">
                    @else
                        <div class="flex size-11 items-center justify-center rounded-xl bg-primary text-lg font-bold text-white shadow-lift">
                            {{ strtoupper(substr($appBrand['companyName'], 0, 1)) }}
                        </div>
                    @endif
                    <span class="text-2xl font-bold tracking-tight text-ink">{{ $appBrand['companyName'] }}</span>
                </a>
                @if ($appBrand['companyName'] && settings('company.tagline'))
                    <p class="mt-2 text-sm text-ink-faint">{{ settings('company.tagline') }}</p>
                @endif
            </div>

            <div class="surface animate-fade-in p-6 sm:p-8">
                {{ $slot }}
            </div>

            <p class="mt-6 text-center text-xs text-ink-faint">
                &copy; {{ date('Y') }} {{ $appBrand['companyName'] }}
            </p>
        </div>
    </body>
</html>
