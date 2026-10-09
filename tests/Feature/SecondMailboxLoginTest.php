<?php

namespace Tests\Feature;

use App\Mail\CustomerEmail;
use App\Mail\CustomerWelcome;
use App\Models\SalesCustomer;
use App\Models\Setting;
use App\Models\User;
use App\Providers\MailConfigServiceProvider;
use App\Support\MailIdentity;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Contracts\Mail\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A company with two mailboxes on one SMTP host — the shape shared hosting
 * (Hostinger, cPanel) provides, where info@ and no-reply@ are separate accounts
 * with separate passwords but relay through the same server.
 *
 * The panel has to accept both login pairs, keep both passwords write-only, and
 * route personal mail through the team credentials while automated mail keeps
 * using the no-reply ones.
 */
class SecondMailboxLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Mail::fake();
    }

    /**
     * @return array<string, string>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'mailer' => 'smtp',
            'host' => 'smtp.hostinger.com',
            'port' => 465,
            'username' => 'no-reply@xyz.com',
            'password' => 'no-reply-secret',
            'encryption' => 'ssl',
            'system_from_address' => 'no-reply@xyz.com',
            'system_from_name' => 'XYZ',
            'reply_to_address' => 'info@xyz.com',
            'reply_to_name' => 'XYZ',
            'personal_from_address' => 'info@xyz.com',
            'personal_from_name' => 'XYZ',
            'personal_use_shared_address' => '1',
        ], $overrides);
    }

    private function superAdmin(): User
    {
        return User::where('email', 'superadmin@nexosdigital.test')->firstOrFail();
    }

    public function test_it_saves_a_second_mailbox_login(): void
    {
        $this->actingAs($this->superAdmin())
            ->put('/settings/mail', $this->payload([
                'team_username' => 'info@xyz.com',
                'team_password' => 'info-secret',
            ]))
            ->assertSessionHasNoErrors();

        Setting::flushCache();

        $this->assertSame('info@xyz.com', Setting::get('mail.team_username'));
        $this->assertSame('info-secret', Setting::get('mail.team_password'));
    }

    public function test_both_mailbox_logins_can_coexist(): void
    {
        $this->actingAs($this->superAdmin())
            ->put('/settings/mail', $this->payload([
                'team_username' => 'info@xyz.com',
                'team_password' => 'info-secret',
            ]))
            ->assertSessionHasNoErrors();

        Setting::flushCache();

        // Neither login may clobber the other.
        $this->assertSame('no-reply@xyz.com', Setting::get('mail.username'));
        $this->assertSame('no-reply-secret', Setting::get('mail.password'));
    }

    public function test_a_team_username_without_a_password_is_rejected(): void
    {
        $this->actingAs($this->superAdmin())
            ->put('/settings/mail', $this->payload([
                'team_username' => 'info@xyz.com',
                'team_password' => '',
            ]))
            ->assertSessionHasErrors('team_password');

        Setting::flushCache();

        $this->assertNull(Setting::get('mail.team_username'));
    }

    public function test_a_team_password_without_a_username_is_rejected(): void
    {
        $this->actingAs($this->superAdmin())
            ->put('/settings/mail', $this->payload([
                'team_username' => '',
                'team_password' => 'info-secret',
            ]))
            ->assertSessionHasErrors('team_username');
    }

    public function test_a_blank_team_password_keeps_the_stored_one(): void
    {
        $this->actingAs($this->superAdmin())
            ->put('/settings/mail', $this->payload([
                'team_username' => 'info@xyz.com',
                'team_password' => 'info-secret',
            ]))
            ->assertSessionHasNoErrors();

        // Re-saving the rest of the form with the box left empty, the way the
        // panel instructs, must not wipe the team credential.
        $this->actingAs($this->superAdmin())
            ->put('/settings/mail', $this->payload([
                'team_username' => 'info@xyz.com',
                'team_password' => '',
            ]))
            ->assertSessionHasNoErrors();

        Setting::flushCache();

        $this->assertSame('info-secret', Setting::get('mail.team_password'));
    }

    public function test_the_stored_team_password_is_never_rendered_back(): void
    {
        $this->actingAs($this->superAdmin())
            ->put('/settings/mail', $this->payload([
                'team_username' => 'info@xyz.com',
                'team_password' => 'team-do-not-leak',
            ]))
            ->assertSessionHasNoErrors();

        $html = $this->actingAs($this->superAdmin())->get('/settings/mail')->getContent();

        $this->assertStringNotContainsString('team-do-not-leak', $html);
    }

    public function test_the_form_offers_a_second_username_and_password(): void
    {
        $html = $this->actingAs($this->superAdmin())->get('/settings/mail')->getContent();

        $this->assertStringContainsString('name="team_username"', $html);
        $this->assertStringContainsString('name="team_password"', $html);
    }

    public function test_no_team_login_means_personal_mail_uses_the_default_transport(): void
    {
        $this->actingAs($this->superAdmin())
            ->put('/settings/mail', $this->payload())
            ->assertSessionHasNoErrors();

        Setting::flushCache();

        $this->assertNull(MailIdentity::teamMailerName());
        $this->assertSame('', Setting::get('mail.team_username'));
        $this->assertSame('', Setting::get('mail.team_password'));
    }

    public function test_a_complete_team_login_selects_the_team_transport(): void
    {
        $this->actingAs($this->superAdmin())
            ->put('/settings/mail', $this->payload([
                'team_username' => 'info@xyz.com',
                'team_password' => 'info-secret',
            ]))
            ->assertSessionHasNoErrors();

        Setting::flushCache();

        $this->assertSame('smtp_team', MailIdentity::teamMailerName());
    }

    public function test_personal_mail_is_sent_through_the_team_transport(): void
    {
        $this->actingAs($this->superAdmin())
            ->put('/settings/mail', $this->payload([
                'team_username' => 'info@xyz.com',
                'team_password' => 'info-secret',
            ]))
            ->assertSessionHasNoErrors();

        Setting::flushCache();

        $customer = SalesCustomer::create([
            'code' => 'C-1', 'company_name' => 'Acme', 'email' => 'buyer@acme.test',
        ]);

        $mail = new CustomerEmail($customer, $this->superAdmin(), 'Hello', 'Body text');

        // Drive the same path the queue worker takes, so the identity and the
        // transport are both resolved exactly as they are in production.
        $mail->send(app(Factory::class));

        // Sent as the team identity, over the transport that carries the team
        // credentials.
        $this->assertSame('info@xyz.com', $mail->from[0]['address']);
        $this->assertSame('smtp_team', $mail->mailer);
    }

    public function test_automated_mail_stays_on_the_default_transport(): void
    {
        $this->actingAs($this->superAdmin())
            ->put('/settings/mail', $this->payload([
                'team_username' => 'info@xyz.com',
                'team_password' => 'info-secret',
            ]))
            ->assertSessionHasNoErrors();

        Setting::flushCache();

        $customer = SalesCustomer::create([
            'code' => 'C-2', 'company_name' => 'Acme', 'email' => 'buyer@acme.test',
        ]);

        $mail = new CustomerWelcome($customer);
        $mail->send(app(Factory::class));

        $this->assertSame('no-reply@xyz.com', $mail->from[0]['address']);
        $this->assertNotSame('smtp_team', $mail->mailer);
    }

    public function test_queued_personal_mail_keeps_the_team_transport_through_serialization(): void
    {
        $this->actingAs($this->superAdmin())
            ->put('/settings/mail', $this->payload([
                'team_username' => 'info@xyz.com',
                'team_password' => 'info-secret',
            ]))
            ->assertSessionHasNoErrors();

        Setting::flushCache();

        $customer = SalesCustomer::create([
            'code' => 'C-3', 'company_name' => 'Acme', 'email' => 'buyer@acme.test',
        ]);

        $mailable = new CustomerEmail($customer, $this->superAdmin(), 'Hi', 'Body');

        // The worker resolves the transport, so drive the same path the queued
        // job takes and then confirm the choice survives being serialised.
        $mailable->send(app(Factory::class));

        $this->assertSame('smtp_team', $mailable->mailer);
        $this->assertSame('smtp_team', unserialize(serialize($mailable))->mailer);
    }

    public function test_the_team_transport_inherits_the_shared_server_settings(): void
    {
        $this->actingAs($this->superAdmin())
            ->put('/settings/mail', $this->payload([
                'team_username' => 'info@xyz.com',
                'team_password' => 'info-secret',
            ]))
            ->assertSessionHasNoErrors();

        Setting::flushCache();

        // Re-derive the config the way a fresh request would, since the
        // provider only runs on boot.
        config([
            'mail.default' => 'log',
            'mail.mailers.smtp.host' => null,
            'mail.mailers.smtp.port' => null,
            'mail.mailers.smtp_team.host' => null,
            'mail.mailers.smtp_team.port' => null,
            'mail.mailers.smtp_team.username' => null,
            'mail.mailers.smtp_team.password' => null,
        ]);

        (new MailConfigServiceProvider(app()))->register();

        $this->assertSame(config('mail.mailers.smtp.host'), config('mail.mailers.smtp_team.host'));
        $this->assertSame(config('mail.mailers.smtp.port'), config('mail.mailers.smtp_team.port'));
        $this->assertSame('info@xyz.com', config('mail.mailers.smtp_team.username'));
        $this->assertSame('info-secret', config('mail.mailers.smtp_team.password'));
    }

    public function test_no_team_login_leaves_the_team_transport_unconfigured(): void
    {
        $this->actingAs($this->superAdmin())
            ->put('/settings/mail', $this->payload())
            ->assertSessionHasNoErrors();

        Setting::flushCache();

        config([
            'mail.mailers.smtp.host' => null,
            'mail.mailers.smtp.port' => null,
            'mail.mailers.smtp_team.username' => 'leftover',
            'mail.mailers.smtp_team.password' => 'leftover',
        ]);

        (new MailConfigServiceProvider(app()))->register();

        // With no team login configured the panel must not invent credentials,
        // so personal mail stays on the default transport rather than
        // authenticating as a mailbox that no longer exists.
        $this->assertSame('leftover', config('mail.mailers.smtp_team.username'));
        $this->assertNull(MailIdentity::teamMailerName());
    }
}
