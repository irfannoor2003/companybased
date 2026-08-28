<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $appBrand['companyName'] ?? 'Application' }} — Package</title>

    @if (! empty($appBrand['favicon']))
        <link rel="icon" href="{{ Storage::url($appBrand['favicon']) }}">
    @else
        <link rel="icon" href="{{ asset('favicon.ico') }}">
    @endif

    <style>
        :root {
            --color-primary: {{ $appBrand['primaryRgb'] ?? '79,70,229' }};
            --color-primary-strong: {{ $appBrand['primaryStrongRgb'] ?? '67,56,202' }};
            --color-accent: {{ $appBrand['accentRgb'] ?? '14,165,233' }};

            --color-canvas: 248 250 252;
            --color-surface: 255 255 255;
            --color-surface-muted: 241 245 249;
            --color-ink: 15 23 42;
            --color-ink-soft: 71 85 105;
            --color-ink-faint: 148 163 184;
            --color-line: 226 232 240;
        }

        .dark {
            --color-canvas: 10 12 20;
            --color-surface: 17 20 31;
            --color-surface-muted: 24 28 42;
            --color-ink: 226 232 240;
            --color-ink-soft: 148 163 184;
            --color-ink-faint: 100 116 139;
            --color-line: 38 43 60;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: rgb(var(--color-canvas));
            color: rgb(var(--color-ink));
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2.5rem 1rem;
        }

        .cb-card {
            width: 100%;
            max-width: 28rem;
            background: rgb(var(--color-surface));
            border: 1px solid rgb(var(--color-line));
            border-radius: 1rem;
            padding: 2rem;
            text-align: center;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
        }

        .cb-icon {
            width: 3.5rem;
            height: 3.5rem;
            margin: 0 auto 1.25rem;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 9999px;
            background: rgb(var(--color-primary) / 0.1);
            color: rgb(var(--color-primary));
        }

        .cb-icon svg { width: 1.75rem; height: 1.75rem; }

        .cb-title { margin: 0; font-size: 1.25rem; font-weight: 600; color: rgb(var(--color-ink)); }

        .cb-text { margin: 0.5rem 0 0; font-size: 0.875rem; line-height: 1.6; color: rgb(var(--color-ink-soft)); }

        .cb-meta {
            margin-top: 1.25rem;
            border: 1px solid rgb(var(--color-line));
            background: rgb(var(--color-surface-muted));
            border-radius: 0.5rem;
            padding: 0.75rem 1rem;
            font-size: 0.75rem;
            color: rgb(var(--color-ink-faint));
        }

        .cb-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-top: 1.5rem;
            border-radius: 0.5rem;
            background: rgb(var(--color-primary));
            color: #fff;
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
            font-weight: 600;
            text-decoration: none;
            transition: background-color 0.15s ease;
        }

        .cb-btn:hover { background: rgb(var(--color-primary-strong)); }
    </style>

    <script>
        (function () {
            var stored = localStorage.getItem('cb-theme');
            var serverDefault = '{{ $appBrand['darkMode'] ?? 'system' }}';
            var pref = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            var theme = stored === 'dark' || stored === 'light'
                ? stored
                : (serverDefault === 'dark' || serverDefault === 'light' ? serverDefault : pref);
            if (theme === 'dark') document.documentElement.classList.add('dark');
        })();
    </script>
</head>
<body>
    <div class="cb-card">
        <div class="cb-icon">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"></path>
            </svg>
        </div>

        @if ($subscription && $subscription->expires_at)
            <h1 class="cb-title">Package Expired</h1>
            <p class="cb-text">Your subscription period has ended, so access to the system has been paused.</p>
            <p class="cb-text">Please contact your administrator or renew the package to continue using the application.</p>

            <div class="cb-meta">
                @if ($subscription->plan_name)
                    Plan: <span style="font-weight:600;color:rgb(var(--color-ink-soft))">{{ $subscription->plan_name }}</span><br>
                @endif
                Expired on: <span style="font-weight:600;color:rgb(var(--color-ink-soft))">{{ $subscription->expires_at->format('d M Y, h:i A') }}</span>
            </div>
        @else
            <h1 class="cb-title">No Active Package</h1>
            <p class="cb-text">No package is currently active, so access to the system has been paused.</p>
            <p class="cb-text">Please contact your administrator to activate a package to continue using the application.</p>
        @endif

        <a href="{{ route('login') }}" class="cb-btn">Back to login</a>
    </div>
</body>
</html>
