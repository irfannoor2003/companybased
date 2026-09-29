<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The mail credentials are the app's most sensitive stored values. They must be
 * validated server-side (never trusted from the form), must never be rendered
 * back to the browser, and must never be silently saved as empty.
 */
class MailSettingsSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Subscription::create([
            'plan_name' => 'Test', 'starts_at' => now()->subMonth(),
            'expires_at' => now()->addYear(), 'is_active' => true,
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Mail::fake();
    }

    /**
     * Some tests blank the MAIL_PASSWORD env fallback to reach a branch, which
     * would otherwise leak into every later test in the process and make results
     * order-dependent. Put it back after each test.
     */
    protected function tearDown(): void
    {
        if ($this->originalMailPassword !== null) {
            putenv('MAIL_PASSWORD='.$this->originalMailPassword);
            $_ENV['MAIL_PASSWORD'] = $this->originalMailPassword;
            $_SERVER['MAIL_PASSWORD'] = $this->originalMailPassword;
        }

        parent::tearDown();
    }

    private ?string $originalMailPassword = null;

    /**
     * Record the current env value so tearDown() can restore it exactly.
     */
    private function neutraliseMailPasswordEnv(): void
    {
        $this->originalMailPassword = $_ENV['MAIL_PASSWORD'] ?? $_SERVER['MAIL_PASSWORD'] ?? getenv('MAIL_PASSWORD') ?: null;

        putenv('MAIL_PASSWORD');
        $_ENV['MAIL_PASSWORD'] = '';
        $_SERVER['MAIL_PASSWORD'] = '';
    }

    /**
     * @return array<string, string>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'mailer' => 'smtp',
            'host' => 'smtp.example.com',
            'port' => 587,
            'username' => 'smtp-user',
            'password' => 'super-secret',
            'encryption' => 'tls',
            'system_from_address' => 'no-reply@example.com',
            'system_from_name' => 'Example Co',
            'reply_to_address' => 'info@example.com',
            'reply_to_name' => 'Example Co',
            'personal_from_address' => 'info@example.com',
            'personal_from_name' => 'Example Co',
            'personal_use_shared_address' => '1',
        ], $overrides);
    }

    private function superAdmin(): User
    {
        return User::where('email', 'superadmin@nexosdigital.test')->firstOrFail();
    }

    public function test_it_saves_valid_credentials(): void
    {
        $this->actingAs($this->superAdmin())
            ->put('/settings/mail', $this->payload())
            ->assertSessionHasNoErrors();

        Setting::flushCache();

        $this->assertSame('smtp-user', Setting::get('mail.username'));
        $this->assertSame('super-secret', Setting::get('mail.password'));
    }

    public function test_it_rejects_an_empty_password_when_none_is_stored(): void
    {
        Setting::forget('mail.password');
        Setting::set('mail.password', '');
        Setting::flushCache();

        $this->actingAs($this->superAdmin())
            ->put('/settings/mail', $this->payload(['password' => '']))
            ->assertSessionHasErrors('password');

        $this->assertSame('', (string) Setting::get('mail.password'), 'nothing may be written on failure');
    }

    public function test_it_rejects_an_empty_username_for_smtp(): void
    {
        $this->actingAs($this->superAdmin())
            ->put('/settings/mail', $this->payload(['username' => '']))
            ->assertSessionHasErrors('username');
    }

    public function test_a_whitespace_only_password_is_never_stored(): void
    {
        Setting::set('mail.password', 'real-credential');
        Setting::flushCache();

        $this->actingAs($this->superAdmin())
            ->put('/settings/mail', $this->payload(['password' => '   ']))
            ->assertSessionHasNoErrors();

        Setting::flushCache();

        $this->assertSame(
            'real-credential',
            Setting::get('mail.password'),
            'whitespace must be treated as blank, never stored as the password',
        );
    }

    public function test_a_blank_password_is_rejected_when_nothing_is_stored(): void
    {
        Setting::forget('mail.password');
        Setting::flushCache();

        // Neutralise the env fallback so the "no credential anywhere" branch is
        // genuinely exercised rather than silently satisfied by .env. tearDown
        // restores the original value.
        $this->neutraliseMailPasswordEnv();

        $this->actingAs($this->superAdmin())
            ->put('/settings/mail', $this->payload(['password' => '']))
            ->assertSessionHasErrors('password');

        $this->assertNull(Setting::get('mail.password'), 'no credential may be written');
    }

    public function test_it_rejects_a_too_short_password(): void
    {
        $this->actingAs($this->superAdmin())
            ->put('/settings/mail', $this->payload(['password' => 'ab']))
            ->assertSessionHasErrors('password');
    }

    public function test_blank_password_keeps_the_stored_credential(): void
    {
        Setting::set('mail.password', 'already-stored');
        Setting::flushCache();

        $this->actingAs($this->superAdmin())
            ->put('/settings/mail', $this->payload(['password' => '']))
            ->assertSessionHasNoErrors();

        Setting::flushCache();

        $this->assertSame('already-stored', Setting::get('mail.password'), 'a blank box must not wipe the credential');
    }

    public function test_new_password_replaces_the_stored_one(): void
    {
        Setting::set('mail.password', 'old-password');
        Setting::flushCache();

        $this->actingAs($this->superAdmin())
            ->put('/settings/mail', $this->payload(['password' => 'brand-new-secret']))
            ->assertSessionHasNoErrors();

        Setting::flushCache();

        $this->assertSame('brand-new-secret', Setting::get('mail.password'));
    }

    public function test_identity_addresses_are_validated(): void
    {
        foreach (['system_from_address', 'reply_to_address', 'personal_from_address'] as $field) {
            $this->actingAs($this->superAdmin())
                ->put('/settings/mail', $this->payload([$field => 'not-an-email']))
                ->assertSessionHasErrors($field);
        }
    }

    public function test_from_and_reply_to_must_differ_from_nothing_and_be_present(): void
    {
        $this->actingAs($this->superAdmin())
            ->put('/settings/mail', $this->payload(['system_from_address' => '']))
            ->assertSessionHasErrors('system_from_address');
    }

    public function test_log_mailer_does_not_require_credentials(): void
    {
        // The log driver authenticates against nothing, so blank is legitimate.
        $this->actingAs($this->superAdmin())
            ->put('/settings/mail', $this->payload(['mailer' => 'log', 'username' => '', 'password' => '']))
            ->assertSessionHasNoErrors();
    }

    public function test_stored_password_is_never_rendered_back_to_the_browser(): void
    {
        Setting::set('mail.password', 'do-not-leak-me');
        Setting::flushCache();

        $html = $this->actingAs($this->superAdmin())->get('/settings/mail')->getContent();

        $this->assertStringNotContainsString('do-not-leak-me', $html, 'the SMTP password must never be sent to the client');
    }

    public function test_non_admin_cannot_change_mail_settings(): void
    {
        $employee = User::where('email', 'employee@nexosdigital.test')->firstOrFail();

        $this->actingAs($employee)
            ->put('/settings/mail', $this->payload())
            ->assertForbidden();
    }

    public function test_settings_do_not_rewrite_the_env_file(): void
    {
        $before = file_get_contents(base_path('.env'));
        $mtime = filemtime(base_path('.env'));

        $this->actingAs($this->superAdmin())
            ->put('/settings/mail', $this->payload())
            ->assertSessionHasNoErrors();

        clearstatcache();

        $this->assertSame($before, file_get_contents(base_path('.env')), '.env must not be modified at runtime');
        $this->assertSame($mtime, filemtime(base_path('.env')));
    }
}
