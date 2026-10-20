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
            <x-input-label for="password" :value="__('Password')" />
            <div x-data="{ visible: false }" class="relative">
                {{-- The static type="password" is load-bearing, not decoration.

                     `:type` is an Alpine binding and does nothing until Alpine has
                     booted. Without a literal `type` attribute the browser falls back
                     to type="text", so the password renders in plain text for as long
                     as it takes to load and execute the bundle -- visible on screen
                     and sitting in the page source. `:type` then takes over and the
                     show/hide toggle works as intended. --}}
                <input id="password" type="password" :type="visible ? 'text' : 'password'" class="input mt-1 block w-full pr-11" name="password" required autocomplete="current-password">
                <button type="button" @click="visible = !visible" class="absolute right-1.5 top-1/2 mt-0.5 flex size-8 -translate-y-1/2 items-center justify-center rounded-lg text-ink-faint transition hover:bg-surface-muted hover:text-ink" :aria-label="visible ? 'Hide password' : 'Show password'">
                    <x-icon name="eye" class="size-4" x-show="!visible" />
                    <x-icon name="eye-off" class="size-4" x-show="visible" x-cloak />
                </button>
            </div>
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        {{-- No "Forgot your password?" link, and no reset screen behind one either:
             this is a single-tenant install where an administrator resets passwords
             from Settings > Users rather than the user requesting a reset e-mail.
             The flow was deleted outright rather than left present-but-unlinked,
             because an unreachable mail-sending endpoint is attack surface with no
             benefit. Pinned by
             AuthFlowTest::test_self_service_password_reset_is_not_reachable. --}}
        <label for="remember_me" class="flex cursor-pointer items-center gap-2">
            <input id="remember_me" type="checkbox" class="size-4 rounded border-line text-primary-ink shadow-sm focus:ring-primary" name="remember">
            <span class="text-sm text-ink-soft">{{ __('Remember me') }}</span>
        </label>

        <x-primary-button class="!w-full !py-2.5">
            {{ __('Sign in') }}
        </x-primary-button>
    </form>
</x-guest-layout>
