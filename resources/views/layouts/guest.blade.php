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
                --color-accent: {{ $appBrand['accentRgb'] }};
            }
        </style>

        <script>
            (function () {
                var stored = localStorage.getItem('cb-theme');
                var pref = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
                var theme = stored === 'dark' || stored === 'light' ? stored : pref;
                if (theme === 'dark') document.documentElement.classList.add('dark');
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
