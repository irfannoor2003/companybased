<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ isset($pageTitle) ? $pageTitle.' — ' : '' }}{{ $appBrand['companyName'] }}</title>

        @if ($appBrand['favicon'])
            <link rel="icon" href="{{ Storage::url($appBrand['favicon']) }}">
        @else
            <link rel="icon" href="{{ asset('favicon.ico') }}">
        @endif

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        {{-- Dynamic brand tokens — re-themes the whole UI without a rebuild. --}}
        <style>
            :root {
                --color-primary: {{ $appBrand['primaryRgb'] }};
                --color-primary-strong: {{ $appBrand['primaryStrongRgb'] }};
                --color-accent: {{ $appBrand['accentRgb'] }};
            }

            {{-- Print cleanly: only the document content, no sidebar/topbar/app chrome. --}}
            @media print {
                aside, header, .toasts, x-toasts, .print\:hidden { display: none !important; }
                body {
                    background: #fff !important;
                }
                main { overflow: visible !important; }
                main > div {
                    max-width: 100% !important;
                    padding: 0 !important;
                }
                .document, .invoice-document {
                    box-shadow: none !important;
                    border: none !important;
                }
            }
        </style>

        {{-- Apply persisted theme before paint to avoid flashes. --}}
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

        @stack('head')
    </head>
    <body class="h-full font-sans antialiased">
        <div
            x-data="appShell()"
            class="flex h-full overflow-hidden bg-canvas"
        >
            @include('layouts.partials.sidebar')

            <div class="flex min-w-0 flex-1 flex-col" :class="collapsed ? 'lg:pl-16' : 'lg:pl-64'">
                @include('layouts.partials.topbar')

                <main class="flex-1 overflow-y-auto">
                    <div class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                        @isset($header)
                            <div class="mb-6">{{ $header }}</div>
                        @endisset

                        @if (session('status'))
                            <x-alert type="success" class="mb-4" dismissible>{{ session('status') }}</x-alert>
                        @endif

                        @if ($errors->any())
                            <x-alert type="danger" class="mb-4" dismissible>
                                <ul class="list-inside list-disc space-y-0.5">
                                    @foreach ($errors->all() as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </x-alert>
                        @endif

                        {{ $slot }}
                    </div>
                </main>
            </div>
        </div>

        <x-toasts />

        <script>
            window.flashToasts = @json(session('toasts', []));
        </script>

        @stack('scripts')
    </body>
</html>
