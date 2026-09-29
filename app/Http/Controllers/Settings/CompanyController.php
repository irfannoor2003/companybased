<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class CompanyController extends Controller
{
    public function edit(): View
    {
        return view('settings.company');
    }

    /**
     * Update the company profile.
     *
     * Two separate forms on the settings screen post here — the profile form and
     * the office-location form — so this is a *partial* update: anything the
     * request omits keeps its stored value. Validating the profile fields as
     * required would make saving office coordinates fail with "The name field is
     * required", which is exactly what it used to do.
     */
    public function updateCompany(Request $request): RedirectResponse
    {
        $this->authorizePermission('settings.company.manage');

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'tagline' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:60'],
            'website' => ['nullable', 'url', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'registration_number' => ['nullable', 'string', 'max:100'],
            'tax_number' => ['nullable', 'string', 'max:100'],
            'currency' => ['nullable', 'string', 'size:3'],
            'base_currency' => ['nullable', 'string', 'size:3'],
            'fiscal_year_start' => ['nullable', 'date'],
            'timezone' => ['nullable', 'timezone'],
            'date_format' => ['nullable', 'string', 'max:30'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'radius' => ['nullable', 'integer', 'min:50', 'max:10000'],
            'qr_code_text' => ['nullable', 'string', 'max:255'],
        ]);

        // Which of the keys this particular form actually submitted.
        $sent = array_keys($request->all());

        $value = function (string $key, mixed $fallback) use ($sent, $data): mixed {
            return in_array($key, $sent, true) ? ($data[$key] ?? null) : $fallback;
        };

        $currentCurrency = settings('company.currency', 'USD');
        $currency = $value('currency', $currentCurrency) ?: $currentCurrency;

        Setting::setMany([
            'company.name' => $value('name', settings('company.name', company_name())) ?: company_name(),
            'company.tagline' => $value('tagline', settings('company.tagline')),
            'company.email' => $value('email', settings('company.email')),
            'company.phone' => $value('phone', settings('company.phone')),
            'company.website' => $value('website', settings('company.website')),
            'company.address' => $value('address', settings('company.address')),
            'company.registration_number' => $value('registration_number', settings('company.registration_number')),
            'company.tax_number' => $value('tax_number', settings('company.tax_number')),
            'company.currency' => $currency,
            'base_currency' => $value('base_currency', settings('base_currency')) ?: $currency,
            'company.fiscal_year_start' => $value('fiscal_year_start', settings('company.fiscal_year_start')),
            'company.timezone' => $value('timezone', settings('company.timezone', 'UTC')) ?: 'UTC',
            'company.date_format' => $value('date_format', settings('company.date_format', 'M d, Y')) ?: 'M d, Y',
            'company.latitude' => $value('latitude', settings('company.latitude')),
            'company.longitude' => $value('longitude', settings('company.longitude')),
            'company.radius' => $value('radius', settings('company.radius', 500)) ?: 500,
            'company.qr_code_text' => $value('qr_code_text', settings('company.qr_code_text')),
        ]);

        return back()->with('toasts', [['type' => 'success', 'message' => 'Company profile updated.']]);
    }

    public function updateBranding(Request $request): RedirectResponse
    {
        $this->authorizePermission('settings.branding.manage');

        $data = $request->validate([
            'primary_color' => ['required', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'accent_color' => ['nullable', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,svg,webp', 'max:2048'],
            'favicon' => ['nullable', 'mimes:png,ico,svg', 'max:512'],
            'dark_mode' => ['required', 'in:system,light,dark'],
        ]);

        if ($request->hasFile('logo')) {
            if ($current = settings('branding.logo')) {
                Storage::delete($current);
            }
            $data['logo'] = $request->file('logo')->store('branding', 'public');
        }

        if ($request->hasFile('favicon')) {
            if ($current = settings('branding.favicon')) {
                Storage::delete($current);
            }
            $data['favicon'] = $request->file('favicon')->store('branding', 'public');
        }

        Setting::setMany([
            'branding.primary_color' => $data['primary_color'],
            'branding.accent_color' => $data['accent_color'] ?? '#0ea5e9',
            'branding.logo' => $data['logo'] ?? settings('branding.logo'),
            'branding.favicon' => $data['favicon'] ?? settings('branding.favicon'),
            'branding.dark_mode' => $data['dark_mode'],
        ]);

        return back()->with('toasts', [['type' => 'success', 'message' => 'Branding updated.']]);
    }

    public function updateNotifications(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email_enabled' => ['boolean'],
            'email_from' => ['nullable', 'email', 'max:255'],
        ]);

        Setting::setMany([
            'notifications.email_enabled' => $request->boolean('email_enabled') ? '1' : '0',
            'notifications.email_from' => $data['email_from'] ?? null,
        ]);

        return back()->with('toasts', [['type' => 'success', 'message' => 'Notification settings updated.']]);
    }

    public function removeBranding(Request $request): Response
    {
        $this->authorizePermission('settings.branding.manage');

        $key = $request->input('asset');

        if (! in_array($key, ['logo', 'favicon'], true)) {
            abort(422);
        }

        if ($current = settings("branding.{$key}")) {
            Storage::delete($current);
            Setting::set("branding.{$key}", null);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Asset removed.']);
        }

        return back()->with('toasts', [['type' => 'success', 'message' => 'Asset removed.']]);
    }
}
