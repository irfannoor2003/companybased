<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\Branding;
use App\Support\MailIdentity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Mail\Markdown;
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
            'encryption' => ['nullable', 'string', 'in:tls,ssl'],

            // Automated mail identity (no-reply)
            'system_from_address' => ['required', 'email', 'max:255'],
            'system_from_name' => ['nullable', 'string', 'max:255'],

            // Where replies to automated mail go
            'reply_to_address' => ['required', 'email', 'max:255'],
            'reply_to_name' => ['nullable', 'string', 'max:255'],

            // Personal mail identity (info@)
            'personal_from_address' => ['required', 'email', 'max:255'],
            'personal_from_name' => ['nullable', 'string', 'max:255'],
            'personal_use_shared_address' => ['nullable', 'boolean'],

            // Second mailbox login on the same SMTP host (info@)
            'team_username' => ['nullable', 'string', 'max:255'],
            'team_password' => ['nullable', 'string', 'min:4', 'max:255'],

            // Accounts & Billing mail identity (invoices, receipts)
            'accounts_from_address' => ['nullable', 'email', 'max:255'],
            'accounts_from_name' => ['nullable', 'string', 'max:255'],
            'accounts_username' => ['nullable', 'string', 'max:255'],
            'accounts_password' => ['nullable', 'string', 'min:4', 'max:255'],

            // Sales & Quotes mail identity
            'sales_from_address' => ['nullable', 'email', 'max:255'],
            'sales_from_name' => ['nullable', 'string', 'max:255'],
            'sales_use_salesman_address' => ['nullable', 'boolean'],
            'sales_username' => ['nullable', 'string', 'max:255'],
            'sales_password' => ['nullable', 'string', 'min:4', 'max:255'],
        ]);

        // An empty password box means "keep the stored one" — the field is a
        // write-only credential and is never rendered back. Everything else is
        // taken at face value.
        $password = filled($data['password'] ?? null)
            ? $data['password'] ?? null
            : Setting::get('mail.password', env('MAIL_PASSWORD'));

        $username = $data['username'] ?? null;

        // Same write-only rule for the second mailbox login. An empty box keeps
        // whatever is stored, so editing unrelated mail settings never wipes it.
        $teamPassword = filled($data['team_password'] ?? null)
            ? $data['team_password'] ?? null
            : Setting::get('mail.team_password', env('MAIL_TEAM_PASSWORD'));

        $teamUsername = $data['team_username'] ?? null;

        // Write-only rule for accounts mailbox
        $accountsPassword = filled($data['accounts_password'] ?? null)
            ? $data['accounts_password'] ?? null
            : Setting::get('mail.accounts_password', env('MAIL_ACCOUNTS_PASSWORD'));

        $accountsUsername = $data['accounts_username'] ?? null;

        // Write-only rule for sales mailbox
        $salesPassword = filled($data['sales_password'] ?? null)
            ? $data['sales_password'] ?? null
            : Setting::get('mail.sales_password', env('MAIL_SALES_PASSWORD'));

        $salesUsername = $data['sales_username'] ?? null;

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
                    ->withInput($request->except(['password', 'team_password', 'accounts_password', 'sales_password']))
                    ->withErrors($validator)
                    ->with('toasts', [['type' => 'error', 'message' => 'Mail credentials are incomplete.']]);
            }
        }

        // The mailbox logins are pairs: half of it would fail authentication.
        $multiValidator = Validator::make([], []);

        if (blank($teamUsername) !== blank($teamPassword)) {
            $multiValidator->errors()->add(
                blank($teamUsername) ? 'team_username' : 'team_password',
                'Provide both the team mailbox username and its password, or leave both blank to use a single mailbox login.',
            );
        }

        if (blank($accountsUsername) !== blank($accountsPassword)) {
            $multiValidator->errors()->add(
                blank($accountsUsername) ? 'accounts_username' : 'accounts_password',
                'Provide both the accounts mailbox username and password, or leave both blank to reuse primary SMTP.',
            );
        }

        if (blank($salesUsername) !== blank($salesPassword)) {
            $multiValidator->errors()->add(
                blank($salesUsername) ? 'sales_username' : 'sales_password',
                'Provide both the sales mailbox username and password, or leave both blank to reuse primary SMTP.',
            );
        }

        if ($multiValidator->errors()->isNotEmpty()) {
            return back()
                ->withInput($request->except(['password', 'team_password', 'accounts_password', 'sales_password']))
                ->withErrors($multiValidator)
                ->with('toasts', [['type' => 'error', 'message' => 'Department mailbox credentials are incomplete.']]);
        }

        Setting::setMany([
            'mail.mailer' => $data['mailer'],
            'mail.host' => $data['host'] ?? null,
            'mail.port' => $data['port'] ?? null,
            'mail.username' => $username,
            'mail.password' => $password,
            'mail.encryption' => $data['encryption'] ?? null,

            'mail.system_from_address' => $data['system_from_address'],
            'mail.system_from_name' => ($data['system_from_name'] ?? null) ?: company_name(),

            'mail.reply_to_address' => $data['reply_to_address'],
            'mail.reply_to_name' => ($data['reply_to_name'] ?? null) ?: company_name(),

            'mail.personal_from_address' => $data['personal_from_address'],
            'mail.personal_from_name' => ($data['personal_from_name'] ?? null) ?: company_name(),
            'mail.personal_use_shared_address' => $request->boolean('personal_use_shared_address') ? '1' : '0',

            // Stored as an empty string, never null: Setting::setMany json-encodes
            // a null into the literal text "null", which reads back as a filled
            // value and would leave the team transport permanently enabled.
            'mail.team_username' => $teamUsername ?: '',
            'mail.team_password' => $teamPassword ?: '',

            'mail.accounts_from_address' => $data['accounts_from_address'] ?? '',
            'mail.accounts_from_name' => ($data['accounts_from_name'] ?? null) ?: (company_name().' Accounts'),
            'mail.accounts_username' => $accountsUsername ?: '',
            'mail.accounts_password' => $accountsPassword ?: '',

            'mail.sales_from_address' => $data['sales_from_address'] ?? '',
            'mail.sales_from_name' => ($data['sales_from_name'] ?? null) ?: (company_name().' Sales'),
            'mail.sales_use_salesman_address' => $request->boolean('sales_use_salesman_address') ? '1' : '0',
            'mail.sales_username' => $salesUsername ?: '',
            'mail.sales_password' => $salesPassword ?: '',
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
            'test_identity' => ['nullable', 'string', 'in:system,accounts,sales,team'],
        ]);

        $to = $data['test_email'];
        $identity = $data['test_identity'] ?? 'system';

        [$fromAddress, $fromName, $mailer] = match ($identity) {
            'accounts' => [MailIdentity::accountsFromAddress(), MailIdentity::accountsFromName(), MailIdentity::accountsMailerName()],
            'sales' => [MailIdentity::salesFromAddress(), MailIdentity::salesFromName(), MailIdentity::salesMailerName()],
            'team' => [MailIdentity::personalFromAddress(), MailIdentity::personalFromName(), MailIdentity::teamMailerName()],
            default => [MailIdentity::systemFromAddress(), MailIdentity::systemFromName(), null],
        };

        try {
            $sender = $mailer ? Mail::mailer($mailer) : Mail::mailer();
            $sender->html(
                $this->renderTestMail($identity, $fromAddress),
                function ($message) use ($to, $fromAddress, $fromName, $identity) {
                    $message->to($to)
                        ->from($fromAddress, $fromName)
                        ->replyTo(...array_values(MailIdentity::replyTo()))
                        ->subject('Mail Server Test ['.ucfirst($identity).'] — '.company_name());
                }
            );

            return back()->with('toasts', [['type' => 'success', 'message' => "Test email [{$identity}] sent to {$to}. Check your inbox."]]);
        } catch (\Throwable $e) {
            report($e);

            return back()->with('toasts', [[
                'type' => 'error',
                'message' => 'Failed to send test email: '.$this->friendlyMailError($e),
            ]]);
        }
    }

    /**
     * Render the test template through the Markdown mail renderer.
     */
    private function renderTestMail(string $identity = 'system', ?string $fromAddress = null): string
    {
        return app(Markdown::class)
            ->render('emails.test-mail', [
                'brand' => Branding::payload(),
                'identity' => $identity,
                'fromAddress' => $fromAddress,
            ])
            ->toHtml();
    }

    /**
     * Turn an SMTP failure into something a Super Admin can act on.
     */
    private function friendlyMailError(\Throwable $e): string
    {
        $message = $e->getMessage();

        return match (true) {
            str_contains($message, 'Failed to authenticate') => 'the SMTP server rejected the username or password. Check the credentials above and make sure your mailbox has SMTP enabled.',
            str_contains($message, 'Connection refused') => 'the SMTP host refused the connection. Check the host and port above.',
            str_contains($message, 'certificate') || str_contains($message, 'SSL') => 'the SSL/TLS handshake failed. Try switching the encryption setting between SSL and TLS.',
            str_contains($message, 'timed out') => 'the connection to the SMTP server timed out. Check the host and port above.',
            default => 'the server rejected the request. Check the host, port, encryption and credentials above.',
        };
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

            'team_username' => settings('mail.team_username', env('MAIL_TEAM_USERNAME', '')),
            // Never send the stored team password back to the browser either.
            'team_password_is_set' => filled(settings('mail.team_password', env('MAIL_TEAM_PASSWORD', ''))),

            'accounts_from_address' => settings('mail.accounts_from_address', ''),
            'accounts_from_name' => settings('mail.accounts_from_name', ''),
            'accounts_username' => settings('mail.accounts_username', env('MAIL_ACCOUNTS_USERNAME', '')),
            'accounts_password_is_set' => filled(settings('mail.accounts_password', env('MAIL_ACCOUNTS_PASSWORD', ''))),

            'sales_from_address' => settings('mail.sales_from_address', ''),
            'sales_from_name' => settings('mail.sales_from_name', ''),
            'sales_use_salesman_address' => MailIdentity::usesSalesmanAddress(),
            'sales_username' => settings('mail.sales_username', env('MAIL_SALES_USERNAME', '')),
            'sales_password_is_set' => filled(settings('mail.sales_password', env('MAIL_SALES_PASSWORD', ''))),

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
