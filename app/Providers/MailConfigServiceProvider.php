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
            'mail.mailers.smtp.scheme' => Setting::get('mail.encryption', config('mail.mailers.smtp.scheme')),
        ]);
    }
}
