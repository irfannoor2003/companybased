<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\View\View;

class MailSettingsController extends Controller
{
    public function edit(): View
    {
        $mail = [
            'mailer' => settings('mail.mailer', env('MAIL_MAILER', 'smtp')),
            'host' => settings('mail.host', env('MAIL_HOST', '127.0.0.1')),
            'port' => settings('mail.port', env('MAIL_PORT', 587)),
            'username' => settings('mail.username', env('MAIL_USERNAME', '')),
            'password' => settings('mail.password', env('MAIL_PASSWORD', '')),
            'encryption' => settings('mail.encryption', env('MAIL_ENCRYPTION', 'tls')),
            'from_address' => settings('mail.from_address', env('MAIL_FROM_ADDRESS', '')),
            'from_name' => settings('mail.from_name', env('MAIL_FROM_NAME', '')),
        ];

        return view('settings.mail', compact('mail'));
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'mailer' => ['required', 'string', 'in:smtp,sendmail,ses,postmark,log'],
            'host' => ['required_with:mailer:smtp', 'nullable', 'string', 'max:255'],
            'port' => ['required_with:mailer:smtp', 'nullable', 'integer', 'min:1', 'max:65535'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'encryption' => ['nullable', 'string', 'in:tls,ssl,null'],
            'from_address' => ['required', 'email', 'max:255'],
            'from_name' => ['required', 'string', 'max:255'],
        ]);

        Setting::setMany([
            'mail.mailer' => $data['mailer'],
            'mail.host' => $data['host'] ?? null,
            'mail.port' => $data['port'] ?? null,
            'mail.username' => $data['username'] ?? null,
            'mail.password' => $data['password'] ?? null,
            'mail.encryption' => $data['encryption'] ?? null,
            'mail.from_address' => $data['from_address'],
            'mail.from_name' => $data['from_name'],
        ]);

        $this->writeEnv([
            'MAIL_MAILER' => $data['mailer'],
            'MAIL_HOST' => $data['host'] ?? '',
            'MAIL_PORT' => $data['port'] ?? '',
            'MAIL_USERNAME' => $data['username'] ?? '',
            'MAIL_PASSWORD' => $data['password'] ?? '',
            'MAIL_ENCRYPTION' => $data['encryption'] ?? '',
            'MAIL_FROM_ADDRESS' => $data['from_address'],
            'MAIL_FROM_NAME' => $data['from_name'],
        ]);

        Artisan::call('config:clear');
        Artisan::call('cache:clear');

        return back()->with('toasts', [['type' => 'success', 'message' => 'Mail server settings updated.']]);
    }

    public function test(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'test_email' => ['required', 'email', 'max:255'],
        ]);

        try {
            $to = $data['test_email'];

            \Mail::raw('This is a test email from ' . settings('company.name', config('app.name')) . ' to verify your mail server configuration.', function ($message) use ($to) {
                $message->to($to)
                    ->subject('Mail Server Test — ' . settings('company.name', config('app.name')));
            });

            return back()->with('toasts', [['type' => 'success', 'message' => "Test email sent to {$to}. Check your inbox."]]);
        } catch (\Throwable $e) {
            return back()->with('toasts', [['type' => 'error', 'message' => 'Failed to send test email: ' . $e->getMessage()]]);
        }
    }

    protected function writeEnv(array $values): void
    {
        $path = base_path('.env');
        $contents = file_get_contents($path);

        foreach ($values as $key => $value) {
            $value = (string) $value;

            // Dotenv requires values with spaces or special characters to be quoted.
            if ($value === '' || preg_match('/[^A-Za-z0-9_.\-@:\/]/', $value)) {
                $value = '"' . str_replace('"', '\\"', $value) . '"';
            }

            $pattern = '/^' . preg_quote($key, '/') . '=.*/m';

            if (preg_match($pattern, $contents)) {
                $contents = preg_replace_callback($pattern, function () use ($key, $value) {
                    return $key . '=' . $value;
                }, $contents);
            } else {
                $contents .= "\n" . $key . '=' . $value;
            }
        }

        file_put_contents($path, $contents);
    }
}
