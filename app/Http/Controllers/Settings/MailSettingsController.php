<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\Branding;
use App\Support\MailIdentity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

class MailSettingsController extends Controller
{
    public function edit(): View
    {
        return view('settings.mail', ['mail' => $this->currentSettings()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            // Transport
            'mailer' => ['required', 'string', 'in:smtp,sendmail,ses,postmark,log'],
            'host' => ['required_with:mailer:smtp', 'nullable', 'string', 'max:255'],
            'port' => ['required_with:mailer:smtp', 'nullable', 'integer', 'min:1', 'max:65535'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'min:4', 'max:255'],
            'encryption' => ['nullable', 'string', 'in:tls,ssl,null'],

            // Automated mail identity (no-reply)
            'system_from_address' => ['required', 'email', 'max:255'],
            'system_from_name' => ['nullable', 'string', 'max:255'],

            // Where replies to automated mail go
            'reply_to_address' => ['required', 'email', 'max:255'],
            'reply_to_name' => ['nullable', 'string', 'max:255'],

            // Staff-sent mail identity (info@)
            'personal_from_address' => ['required', 'email', 'max:255'],
            'personal_from_name' => ['nullable', 'string', 'max:255'],
            'personal_use_shared_address' => ['nullable', 'boolean'],
        ]);

        // An empty password box means "keep the stored one" — the field is a
        // write-only credential and is never rendered back. Everything else is
        // taken at face value.
        $password = filled($data['password'] ?? null)
            ? $data['password']
            : Setting::get('mail.password', env('MAIL_PASSWORD'));

        $username = $data['username'] ?? null;

        // Credentials are only meaningful for transports that authenticate.
        $requiresAuth = in_array($data['mailer'], ['smtp', 'ses', 'postmark'], true);

        if ($requiresAuth) {
            $validator = Validator::make([], []);

            if (blank($username)) {
                $validator->errors()->add('username', 'The username field is required when using '.$data['mailer'].'.');
            }

            // A blank SMTP username is almost always a misconfiguration that
            // silently stops every queued email, so refuse to save it.
            if (blank($password)) {
                $validator->errors()->add('password', 'The password field is required when using '.$data['mailer'].'.');
            }

            if ($validator->errors()->isNotEmpty()) {
                return back()
                    ->withInput($request->except('password'))
                    ->withErrors($validator)
                    ->with('toasts', [['type' => 'error', 'message' => 'Mail credentials are incomplete.']]);
            }
        }

        Setting::setMany([
            'mail.mailer' => $data['mailer'],
            'mail.host' => $data['host'] ?? null,
            'mail.port' => $data['port'] ?? null,
            'mail.username' => $username,
            'mail.password' => $password,
            'mail.encryption' => $data['encryption'] ?? null,

            'mail.system_from_address' => $data['system_from_address'],
            'mail.system_from_name' => $data['system_from_name'] ?: company_name(),

            'mail.reply_to_address' => $data['reply_to_address'],
            'mail.reply_to_name' => $data['reply_to_name'] ?: company_name(),

            'mail.personal_from_address' => $data['personal_from_address'],
            'mail.personal_from_name' => $data['personal_from_name'] ?: company_name(),
            'mail.personal_use_shared_address' => $request->boolean('personal_use_shared_address') ? '1' : '0',
        ]);

        // No .env rewrite and no `config:clear` here: MailConfigServiceProvider
        // overlays these settings on top of the environment at runtime, so the
        // change takes effect immediately and survives a config cache.
        return back()->with('toasts', [['type' => 'success', 'message' => 'Mail server settings updated.']]);
    }

    public function test(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'test_email' => ['required', 'email', 'max:255'],
        ]);

        $to = $data['test_email'];

        try {
            Mail::html(
                view('emails.test-mail', ['brand' => Branding::payload()])->render(),
                function ($message) use ($to) {
                    $message->to($to)
                        ->from(MailIdentity::systemFromAddress(), MailIdentity::systemFromName())
                        ->replyTo(...array_values(MailIdentity::replyTo()))
                        ->subject('Mail Server Test — '.company_name());
                }
            );

            return back()->with('toasts', [['type' => 'success', 'message' => "Test email sent to {$to}. Check your inbox."]]);
        } catch (\Throwable $e) {
            return back()->with('toasts', [['type' => 'error', 'message' => 'Failed to send test email: '.$e->getMessage()]]);
        }
    }

    /**
     * Settings-backed values for the form, falling back to the MAIL_* env
     * values so the panel is populated on a fresh install.
     *
     * @return array<string, mixed>
     */
    private function currentSettings(): array
    {
        return [
            'mailer' => settings('mail.mailer', env('MAIL_MAILER', 'smtp')),
            'host' => settings('mail.host', env('MAIL_HOST', '127.0.0.1')),
            'port' => settings('mail.port', env('MAIL_PORT', 587)),
            'username' => settings('mail.username', env('MAIL_USERNAME', '')),
            // Never send the stored password back to the browser.
            'password' => null,
            'password_is_set' => filled(settings('mail.password', env('MAIL_PASSWORD', ''))),
            'encryption' => settings('mail.encryption', env('MAIL_ENCRYPTION', 'tls')),

            'system_from_address' => MailIdentity::systemFromAddress(),
            'system_from_name' => MailIdentity::systemFromName(),
            'reply_to_address' => MailIdentity::replyToAddress() ?: MailIdentity::systemFromAddress(),
            'reply_to_name' => MailIdentity::replyToName() ?: company_name(),
            'personal_from_address' => MailIdentity::personalFromAddress(),
            'personal_from_name' => MailIdentity::personalFromName(),
            'personal_use_shared_address' => MailIdentity::usesSharedPersonalAddress(),
        ];
    }
}
