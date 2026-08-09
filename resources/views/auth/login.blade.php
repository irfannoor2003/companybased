<x-guest-layout :pageTitle="'Sign in'">
    <h1 class="text-lg font-bold text-ink">Welcome back</h1>
    <p class="mt-1 text-sm text-ink-faint">Sign in to {{ $appBrand['companyName'] }} to continue.</p>

    <x-auth-session-status class="mt-4" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-5">
        @csrf

        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" class="mt-1 block w-full" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div>
            <div class="flex items-center justify-between">
                <x-input-label for="password" :value="__('Password')" />
                @if (Route::has('password.request'))
                    <a class="text-xs font-medium text-primary hover:text-primary-strong" href="{{ route('password.request') }}">
                        {{ __('Forgot your password?') }}
                    </a>
                @endif
            </div>
            <x-text-input id="password" class="mt-1 block w-full" type="password" name="password" required autocomplete="current-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <label for="remember_me" class="flex cursor-pointer items-center gap-2">
            <input id="remember_me" type="checkbox" class="size-4 rounded border-line text-primary shadow-sm focus:ring-primary" name="remember">
            <span class="text-sm text-ink-soft">{{ __('Remember me') }}</span>
        </label>

        <x-primary-button class="!w-full !py-2.5">
            {{ __('Sign in') }}
        </x-primary-button>
    </form>
</x-guest-layout>
