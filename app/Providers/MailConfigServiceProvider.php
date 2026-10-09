<?php

namespace App\Providers;

use App\Models\Setting;
use Illuminate\Support\ServiceProvider;

/**
 * Applies the Super Admin's mail settings (Settings → Mail Server) on top of the
 * MAIL_* environment values at runtime.
 *
 * Previously MailSettingsController wrote SMTP credentials straight into .env
 * and then ran `config:clear`. That made the panel work, but it required a
 * writable project root (unavailable on most shared hosting) and clearing the
 * config cache took the whole site down until `config:cache` was re-run.
 *
 * Overlaying config here means the database is the source of truth, .env stays
 * a harmless bootstrap fallback, and the values survive a `config:cache`
 * deployment. Guarded so it never runs before the database is reachable
 * (fresh install, `migrate`, queued jobs booting early).
 */
class MailConfigServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->booted(function (): void {
            $this->applyMailSettings();
        });
    }

    private function applyMailSettings(): void
    {
        try {
            $mailer = Setting::get('mail.mailer');
        } catch (\Throwable) {
            // No database yet (e.g. `php artisan migrate`); keep the env config.
            return;
        }

        if (! filled($mailer)) {
            return;
        }

        config([
            'mail.default' => $mailer,
        ]);

        // Only overlay the SMTP transport fields when SMTP is actually in use;
        // the other drivers take their credentials from their own env keys.
        if ($mailer !== 'smtp') {
            return;
        }

        config([
            'mail.mailers.smtp.host' => Setting::get('mail.host', config('mail.mailers.smtp.host')),
            'mail.mailers.smtp.port' => (int) Setting::get('mail.port', config('mail.mailers.smtp.port')),
            'mail.mailers.smtp.username' => Setting::get('mail.username', config('mail.mailers.smtp.username')),
            'mail.mailers.smtp.password' => Setting::get('mail.password', config('mail.mailers.smtp.password')),
            'mail.mailers.smtp.scheme' => $this->smtpScheme(
                Setting::get('mail.encryption', config('mail.mailers.smtp.scheme')),
            ),
        ]);

        $this->applyTeamMailer();
        $this->applyAccountsMailer();
        $this->applySalesMailer();
    }

    /**
     * Translate the panel's "encryption" choice into a scheme Symfony Mailer
     * actually accepts.
     *
     * The panel offers TLS / SSL / None because that is how SMTP servers are
     * described to administrators, but Symfony's SMTP DSN only knows two
     * schemes: `smtp` and `smtps`. Passing the label straight through makes
     * `UnsupportedSchemeException` escape from `EsmtpTransportFactory` the
     * first time anything tries to resolve the transport — which happened
     * *before* a queued mail was dispatched, so it turned ordinary "create
     * customer" requests into 500s.
     *
     * `ssl` is implicit TLS, i.e. `smtps`. `tls` is opportunistic STARTTLS,
     * which is the behaviour of the plain `smtp` scheme. "None" is `smtp`
     * too, since an unencrypted server simply never offers STARTTLS.
     */
    private function smtpScheme(mixed $encryption): ?string
    {
        return match (strtolower(trim((string) $encryption))) {
            'ssl', 'smtps' => 'smtps',
            default => 'smtp',
        };
    }

    /**
     * The team mailbox is a second login on the *same* SMTP server, so the
     * connection settings are inherited rather than configured twice. Only the
     * username and password are its own.
     *
     * Left untouched when no team login is stored, so a single-mailbox install
     * resolves no second transport and personal mail keeps using the default.
     */
    private function applyTeamMailer(): void
    {
        $username = Setting::get('mail.team_username') ?: env('MAIL_TEAM_USERNAME');
        $password = Setting::get('mail.team_password') ?: env('MAIL_TEAM_PASSWORD');

        if (! filled($username) || ! filled($password)) {
            return;
        }

        config([
            'mail.mailers.smtp_team.host' => config('mail.mailers.smtp.host'),
            'mail.mailers.smtp_team.port' => config('mail.mailers.smtp.port'),
            'mail.mailers.smtp_team.scheme' => config('mail.mailers.smtp.scheme'),
            'mail.mailers.smtp_team.username' => $username,
            'mail.mailers.smtp_team.password' => $password,
        ]);
    }

    private function applyAccountsMailer(): void
    {
        $username = Setting::get('mail.accounts_username') ?: env('MAIL_ACCOUNTS_USERNAME');
        $password = Setting::get('mail.accounts_password') ?: env('MAIL_ACCOUNTS_PASSWORD');

        if (! filled($username) || ! filled($password)) {
            return;
        }

        config([
            'mail.mailers.smtp_accounts.host' => config('mail.mailers.smtp.host'),
            'mail.mailers.smtp_accounts.port' => config('mail.mailers.smtp.port'),
            'mail.mailers.smtp_accounts.scheme' => config('mail.mailers.smtp.scheme'),
            'mail.mailers.smtp_accounts.username' => $username,
            'mail.mailers.smtp_accounts.password' => $password,
        ]);
    }

    private function applySalesMailer(): void
    {
        $username = Setting::get('mail.sales_username') ?: env('MAIL_SALES_USERNAME');
        $password = Setting::get('mail.sales_password') ?: env('MAIL_SALES_PASSWORD');

        if (! filled($username) || ! filled($password)) {
            return;
        }

        config([
            'mail.mailers.smtp_sales.host' => config('mail.mailers.smtp.host'),
            'mail.mailers.smtp_sales.port' => config('mail.mailers.smtp.port'),
            'mail.mailers.smtp_sales.scheme' => config('mail.mailers.smtp.scheme'),
            'mail.mailers.smtp_sales.username' => $username,
            'mail.mailers.smtp_sales.password' => $password,
        ]);
    }
}
