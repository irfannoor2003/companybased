<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $ok ? 'Clocked in' : 'Attendance' }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="flex min-h-screen items-center justify-center bg-slate-100 p-6">
    <div class="w-full max-w-sm rounded-2xl bg-white p-8 text-center shadow-lg">
        <div class="mx-auto flex size-16 items-center justify-center rounded-full {{ $ok ? 'bg-emerald-100 text-emerald-600' : 'bg-rose-100 text-rose-600' }}">
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

        <h1 class="mt-4 text-xl font-bold text-slate-900">{{ $message }}</h1>

        @if (! empty($name))
            <p class="mt-2 text-sm text-slate-500">{{ $name }}</p>
        @endif

        @if (! empty($time))
            <p class="mt-1 font-mono text-sm text-slate-500">{{ $time }}</p>
        @endif
    </div>
</body>
</html>
