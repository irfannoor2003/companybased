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
                --color-primary-ink: {{ $appBrand['primaryRgb'] }};
                --color-accent: {{ $appBrand['accentRgb'] }};
            }

            {{--
                Dark mode needs its own brand ink. Without this the app.css default
                would apply, which is correct only for the default indigo -- a tenant
                with a custom brand would get indigo-tinted link text in dark mode.
                `--color-primary` itself is deliberately NOT changed here: it is a
                fill, and white text on it has to stay readable.
            --}}
            .dark {
                --color-primary-ink: {{ $appBrand['primaryInkRgb'] }};
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

                /*
                    While the preview overlay is open, the thing to print is the
                    previewed document.

                    The overlay is rendered outside the app shell (see
                    @stack('preview')), so hiding the shell removes the chrome
                    and the data-entry form while leaving the document. An earlier
                    version kept the overlay inside <main> and printed a blank
                    page.

                    .app-shell is h-full, which is a full viewport of layout. In
                    print that produced an empty page *before* the document, so
                    it is removed outright rather than merely having its parts
                    hidden.
                */
                body.document-preview-open .app-shell { display: none !important; }
                body.document-preview-open aside,
                body.document-preview-open header { display: none !important; }

                /*
                    <main> holds the data-entry form. The rule above removes it
                    along with the rest of the shell, but it is repeated
                    explicitly because the overlay is not always outside the
                    shell: a screen that pushes the preview from inside its own
                    content would otherwise print the form above the document.
                */
                body.document-preview-open main { display: none !important; }

                body.document-preview-open .document-preview-overlay {
                    position: static !important;
                    display: block !important;
                    overflow: visible !important;
                    height: auto !important;
                    background: #fff !important;
                }

                /* The overlay's own toolbar is UI, not part of the document. */
                body.document-preview-open .document-preview-overlay > .sticky { display: none !important; }

                /* The centring canvas is page furniture too. */
                body.document-preview-open .document-preview-overlay > .flex { display: block !important; padding: 0 !important; }

                body.document-preview-open .document-preview-sheet {
                    max-width: none !important;
                    padding: 0 !important;
                    box-shadow: none !important;
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
            class="app-shell flex h-full overflow-hidden bg-canvas"
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

        {{--
            The document preview overlay is pushed here, deliberately outside
            <main>. The print stylesheet hides <main> so that printing from a
            create screen yields the document rather than the data-entry form,
            and an overlay nested inside <main> would be hidden along with it —
            printing a blank page.
        --}}
        @stack('preview')

        @stack('scripts')
    </body>
</html>
