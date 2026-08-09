<x-settings-layout page-title="Company Profile">
    <div class="space-y-6">
        {{-- Company profile --}}
        <x-card title="Company details" description="This information appears on documents, emails and reports.">
            <form method="POST" action="{{ route('settings.company.update') }}" class="space-y-5">
                @csrf
                @method('PUT')

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-input name="name" label="Company name" required value="{{ old('name', settings('company.name', company_name())) }}" />
                    <x-input name="tagline" label="Tagline" value="{{ old('tagline', settings('company.tagline')) }}" />
                    <x-input name="email" label="Email" type="email" value="{{ old('email', settings('company.email')) }}" />
                    <x-input name="phone" label="Phone" value="{{ old('phone', settings('company.phone')) }}" />
                    <x-input name="website" label="Website" value="{{ old('website', settings('company.website')) }}" hint="https://…" />
                    <x-input name="registration_number" label="Registration number" value="{{ old('registration_number', settings('company.registration_number')) }}" />
                    <x-input name="tax_number" label="Tax / VAT number" value="{{ old('tax_number', settings('company.tax_number')) }}" />
                    <x-input name="currency" label="Base currency" value="{{ old('currency', settings('company.currency', 'USD')) }}" maxlength="3" hint="ISO 4217 code, e.g. USD, EUR, PKR" />
                </div>

                <div>
                    <x-textarea name="address" label="Address">{{ old('address', settings('company.address')) }}</x-textarea>
                </div>

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-3">
                    <x-input name="fiscal_year_start" label="Fiscal year start" type="date" value="{{ old('fiscal_year_start', settings('company.fiscal_year_start')) }}" />
                    <x-select name="timezone" label="Timezone">
                        @foreach (timezone_identifiers_list() as $tz)
                            <option value="{{ $tz }}" @selected(old('timezone', settings('company.timezone', 'UTC')) === $tz)>{{ $tz }}</option>
                        @endforeach
                    </x-select>
                    <x-select name="date_format" label="Date format">
                        @foreach (['M d, Y' => 'Jan 5, 2026', 'd M Y' => '5 Jan 2026', 'Y-m-d' => '2026-01-05', 'd/m/Y' => '05/01/2026'] as $format => $preview)
                            <option value="{{ $format }}" @selected(old('date_format', settings('company.date_format', 'M d, Y')) === $format)>{{ $preview }}</option>
                        @endforeach
                    </x-select>
                </div>

                <div class="flex justify-end border-t border-line pt-4">
                    <x-button type="submit" icon="save">Save company details</x-button>
                </div>
            </form>
        </x-card>

        {{-- Branding & theme --}}
        <x-card title="Branding & theme" description="Your colors and logo re-theme the entire app — no rebuild needed.">
            <form method="POST" action="{{ route('settings.branding.update') }}" enctype="multipart/form-data" class="space-y-5">
                @csrf
                @method('PUT')

                <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                    <div>
                        <span class="label">Primary color</span>
                        <div class="flex items-center gap-3">
                            <label class="relative size-10 cursor-pointer overflow-hidden rounded-lg border border-line shadow-sm">
                                <span class="absolute inset-0" style="background-color: {{ settings('branding.primary_color', '#4f46e5') }}"></span>
                                <input type="color" name="primary_color" value="{{ old('primary_color', settings('branding.primary_color', '#4f46e5')) }}" class="absolute inset-0 cursor-pointer opacity-0">
                            </label>
                            <span class="text-sm text-ink-soft">{{ settings('branding.primary_color', '#4f46e5') }}</span>
                        </div>
                        @error('primary_color')<p class="mt-1.5 text-xs text-rose-600">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <span class="label">Accent color</span>
                        <div class="flex items-center gap-3">
                            <label class="relative size-10 cursor-pointer overflow-hidden rounded-lg border border-line shadow-sm">
                                <span class="absolute inset-0" style="background-color: {{ settings('branding.accent_color', '#0ea5e9') }}"></span>
                                <input type="color" name="accent_color" value="{{ old('accent_color', settings('branding.accent_color', '#0ea5e9')) }}" class="absolute inset-0 cursor-pointer opacity-0">
                            </label>
                            <span class="text-sm text-ink-soft">{{ settings('branding.accent_color', '#0ea5e9') }}</span>
                        </div>
                        @error('accent_color')<p class="mt-1.5 text-xs text-rose-600">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <span class="label">Default appearance</span>
                        <x-select name="dark_mode">
                            <option value="system" @selected(settings('branding.dark_mode', 'system') === 'system')>Follow system</option>
                            <option value="light" @selected(settings('branding.dark_mode') === 'light')>Light</option>
                            <option value="dark" @selected(settings('branding.dark_mode') === 'dark')>Dark</option>
                        </x-select>
                        <p class="mt-1.5 text-xs text-ink-faint">Users can still switch themes individually.</p>
                    </div>

                    <div>
                        <span class="label">Logo</span>
                        <div class="flex items-center gap-3">
                            @if (settings('branding.logo'))
                                <img src="{{ Storage::url(settings('branding.logo')) }}" alt="Logo" class="h-10 w-10 rounded-lg object-contain border border-line p-1">
                            @else
                                <div class="flex size-10 items-center justify-center rounded-lg bg-surface-muted text-ink-faint">
                                    <x-icon name="image" class="size-5" />
                                </div>
                            @endif
                            <label class="btn-secondary btn-sm cursor-pointer">
                                <x-icon name="upload" class="size-4" />
                                Choose file
                                <input type="file" name="logo" accept="image/*" class="hidden">
                            </label>
                            @if (settings('branding.logo'))
                                <form method="POST" action="{{ route('settings.branding.remove') }}">
                                    @csrf
                                    @method('DELETE')
                                    <input type="hidden" name="asset" value="logo">
                                    <button type="submit" class="btn-ghost btn-sm text-rose-500">Remove</button>
                                </form>
                            @endif
                        </div>
                        @error('logo')<p class="mt-1.5 text-xs text-rose-600">{{ $message }}</p>@enderror
                    </div>

                    <div class="sm:col-span-2">
                        <span class="label">Favicon</span>
                        <div class="flex items-center gap-3">
                            @if (settings('branding.favicon'))
                                <img src="{{ Storage::url(settings('branding.favicon')) }}" alt="Favicon" class="h-8 w-8 rounded border border-line p-0.5">
                            @else
                                <div class="flex size-8 items-center justify-center rounded bg-surface-muted text-ink-faint">
                                    <x-icon name="globe" class="size-4" />
                                </div>
                            @endif
                            <label class="btn-secondary btn-sm cursor-pointer">
                                <x-icon name="upload" class="size-4" />
                                Choose file
                                <input type="file" name="favicon" accept=".png,.ico,.svg" class="hidden">
                            </label>
                            @if (settings('branding.favicon'))
                                <form method="POST" action="{{ route('settings.branding.remove') }}">
                                    @csrf
                                    @method('DELETE')
                                    <input type="hidden" name="asset" value="favicon">
                                    <button type="submit" class="btn-ghost btn-sm text-rose-500">Remove</button>
                                </form>
                            @endif
                        </div>
                        @error('favicon')<p class="mt-1.5 text-xs text-rose-600">{{ $message }}</p>@enderror
                    </div>
                </div>

                <div class="flex justify-end border-t border-line pt-4">
                    <x-button type="submit" icon="save">Save branding</x-button>
                </div>
            </form>
        </x-card>

        {{-- Notifications --}}
        <x-card title="Notifications" description="Channel defaults used across the app. SMS and WhatsApp providers can be added later.">
            <form method="POST" action="{{ route('settings.notifications.update') }}" class="space-y-5">
                @csrf
                @method('PUT')

                <x-toggle name="email_enabled" label="Email notifications" description="Send transactional emails (orders, invoices, tracking)."
                    checked="{{ settings('notifications.email_enabled', '1') === '1' }}" />

                <div class="max-w-sm">
                    <x-input name="email_from" label="Sender address" type="email" value="{{ old('email_from', settings('notifications.email_from', 'no-reply@companybased.test')) }}" />
                </div>

                <div class="flex justify-end border-t border-line pt-4">
                    <x-button type="submit" icon="save">Save notification settings</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-settings-layout>
