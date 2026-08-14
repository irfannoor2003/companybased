<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class CompanyController extends Controller
{
    public function edit(): View
    {
        return view('settings.company');
    }

    public function updateCompany(Request $request): RedirectResponse
    {
        $this->authorizePermission('settings.company.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'tagline' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:60'],
            'website' => ['nullable', 'url', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'registration_number' => ['nullable', 'string', 'max:100'],
            'tax_number' => ['nullable', 'string', 'max:100'],
            'currency' => ['required', 'string', 'size:3'],
            'base_currency' => ['nullable', 'string', 'size:3'],
            'fiscal_year_start' => ['nullable', 'date'],
            'timezone' => ['required', 'timezone'],
            'date_format' => ['required', 'string', 'max:30'],
        ]);

        Setting::setMany([
            'company.name' => $data['name'],
            'company.tagline' => $data['tagline'] ?? null,
            'company.email' => $data['email'] ?? null,
            'company.phone' => $data['phone'] ?? null,
            'company.website' => $data['website'] ?? null,
            'company.address' => $data['address'] ?? null,
            'company.registration_number' => $data['registration_number'] ?? null,
            'company.tax_number' => $data['tax_number'] ?? null,
            'company.currency' => $data['currency'],
            'base_currency' => $data['base_currency'] ?? $data['currency'],
            'company.fiscal_year_start' => $data['fiscal_year_start'] ?? null,
            'company.timezone' => $data['timezone'],
            'company.date_format' => $data['date_format'],
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

    public function removeBranding(Request $request): \Symfony\Component\HttpFoundation\Response
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
