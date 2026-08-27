<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Package Expired</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #0f172a;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            color: #e2e8f0;
            padding: 1.5rem;
        }
        .card {
            max-width: 30rem;
            width: 100%;
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 1rem;
            padding: 2.5rem 2rem;
            text-align: center;
            box-shadow: 0 20px 50px rgba(0,0,0,.35);
        }
        .icon {
            width: 3.5rem; height: 3.5rem;
            margin: 0 auto 1.25rem;
            display: flex; align-items: center; justify-content: center;
            border-radius: 9999px;
            background: rgba(244,63,94,.15);
            color: #fb7185;
        }
        h1 { font-size: 1.5rem; margin: 0 0 .5rem; color: #f8fafc; }
        p { font-size: .95rem; line-height: 1.6; color: #cbd5e1; margin: .35rem 0; }
        .meta {
            margin-top: 1.5rem;
            font-size: .8rem;
            color: #94a3b8;
            border-top: 1px solid #334155;
            padding-top: 1rem;
        }
        .btn {
            display: inline-block;
            margin-top: 1.5rem;
            padding: .6rem 1.25rem;
            border-radius: .5rem;
            background: #6366f1;
            color: #fff;
            text-decoration: none;
            font-size: .9rem;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" style="width:1.75rem;height:1.75rem;">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
            </svg>
        </div>
        <h1>Package Expired</h1>
        <p>Your subscription period has ended, so access to the system has been paused.</p>
        <p>Please contact your administrator or renew the package to continue using the application.</p>

        @if ($subscription && $subscription->expires_at)
            <div class="meta">
                @if ($subscription->plan_name)
                    Plan: <strong>{{ $subscription->plan_name }}</strong><br>
                @endif
                Expired on: {{ $subscription->expires_at->format('d M Y, h:i A') }}
            </div>
        @endif
    </div>
</body>
</html>
