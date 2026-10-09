<?php

namespace Tests\Feature;

use App\Models\SalesCustomer;
use App\Models\Setting;
use App\Providers\MailConfigServiceProvider;
use App\Support\MailIdentity;
use Illuminate\Support\Facades\Mail;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * The Settings → Mail Server panel offers the encryption choices an
 * administrator recognises — TLS, SSL, None — but Symfony Mailer does not speak
 * those words. Its SMTP DSN knows exactly two schemes, `smtp` and `smtps`, and
 * anything else raises UnsupportedSchemeException the moment the transport is
 * built.
 *
 * Because the transport is built eagerly (including for a queued mail, which has
 * to resolve its mailer in order to dispatch to the queue), that exception was
 * raised inside ordinary requests. It surfaced as a 500 on "create customer" —
 * for a customer record that had in fact already been saved — and the retry
 * then tripped the unique constraint on the short code or email address.
 *
 * These tests pin both halves of the fix: the scheme the panel stores is
 * translated into one Symfony accepts, and a customer is never lost to a mail
 * problem.
 */
class MailTransportSchemeTest extends TestCase
{
    use SeedsDatabase;

    /**
     * Re-derive the mail config the way a fresh request would, since the
     * provider only runs on boot.
     */
    private function reloadMailConfig(): void
    {
        Setting::flushCache();

        (new MailConfigServiceProvider(app()))->register();
    }

    public function test_ssl_encryption_resolves_to_the_implicit_tls_scheme(): void
    {
        Setting::setMany([
            'mail.mailer' => 'smtp',
            'mail.host' => 'smtp.example.com',
            'mail.port' => '465',
            'mail.username' => 'no-reply@example.com',
            'mail.password' => 'secret-value',
            'mail.encryption' => 'ssl',
        ]);

        $this->reloadMailConfig();

        // "ssl" is the panel's word for implicit TLS, which Symfony spells
        // `smtps`. Passing the label straight through is what threw.
        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
    }

    public function test_tls_encryption_resolves_to_the_plain_smtp_scheme(): void
    {
        Setting::setMany([
            'mail.mailer' => 'smtp',
            'mail.host' => 'smtp.example.com',
            'mail.port' => '587',
            'mail.username' => 'no-reply@example.com',
            'mail.password' => 'secret-value',
            'mail.encryption' => 'tls',
        ]);

        $this->reloadMailConfig();

        // STARTTLS is opportunistic and is the behaviour of the plain `smtp`
        // scheme — the server only upgrades if it advertises STARTTLS.
        $this->assertSame('smtp', config('mail.mailers.smtp.scheme'));
    }

    public function test_no_encryption_resolves_to_the_plain_smtp_scheme(): void
    {
        Setting::setMany([
            'mail.mailer' => 'smtp',
            'mail.host' => 'smtp.example.com',
            'mail.port' => '25',
            'mail.username' => 'no-reply@example.com',
            'mail.password' => 'secret-value',
            'mail.encryption' => '',
        ]);

        $this->reloadMailConfig();

        $this->assertSame('smtp', config('mail.mailers.smtp.scheme'));
    }

    /**
     * The end-to-end proof: building the transport must not raise.
     *
     * Constructing an SMTP transport does not open a socket, so this is safe to
     * assert directly and is the exact call that used to fatal.
     */
    public function test_a_transport_can_be_built_for_every_encryption_choice(): void
    {
        foreach (['tls', 'ssl', ''] as $encryption) {
            Setting::setMany([
                'mail.mailer' => 'smtp',
                'mail.host' => 'smtp.example.com',
                'mail.port' => '587',
                'mail.username' => 'no-reply@example.com',
                'mail.password' => 'secret-value',
                'mail.encryption' => $encryption,
            ]);

            $this->reloadMailConfig();

            $transport = Mail::mailer('smtp')->getSymfonyTransport();

            $this->assertNotNull($transport, "encryption '{$encryption}' must yield a usable transport");
        }
    }

    public function test_the_team_transport_inherits_the_mapped_scheme(): void
    {
        Setting::setMany([
            'mail.mailer' => 'smtp',
            'mail.host' => 'smtp.example.com',
            'mail.port' => '465',
            'mail.username' => 'no-reply@example.com',
            'mail.password' => 'secret-value',
            'mail.encryption' => 'ssl',
            'mail.team_username' => 'info@example.com',
            'mail.team_password' => 'team-secret',
        ]);

        $this->reloadMailConfig();

        // The team mailbox is a second login on the same server, so it shares
        // the connection — and therefore the scheme — with the default mailer.
        // Mapping only one of the two would leave personal mail broken.
        $this->assertSame('smtp_team', MailIdentity::teamMailerName());
        $this->assertSame(
            config('mail.mailers.smtp.scheme'),
            config('mail.mailers.smtp_team.scheme'),
            'the team transport must inherit the mapped scheme, not the raw panel value',
        );
    }

    public function test_the_encryption_field_rejects_the_literal_string_null(): void
    {
        // The form posts '' for "None". A 'null' option would be stored as the
        // four-character string and read back as a filled value, which is the
        // same class of bug the team-login fields guard against.
        $this->actingAs($this->userForRole('Super Admin'))
            ->put('/settings/mail', $this->mailPayload(['encryption' => 'null']))
            ->assertSessionHasErrors('encryption');
    }

    public function test_creating_a_customer_survives_a_mail_transport_that_cannot_be_built(): void
    {
        // Not faked: the point is that resolving the real mailer fails. An
        // unsupported scheme is the cheapest way to reproduce the production
        // failure without needing an unreachable server.
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.scheme' => 'ssl',
            'mail.mailers.smtp.host' => 'smtp.example.com',
            'mail.mailers.smtp.port' => 587,
        ]);

        $response = $this->actingAs($this->userForRole('Admin'))
            ->post('/sales/customers', [
                'company_name' => 'Nexos Digital',
                'contact_name' => 'Ops Team',
                'email' => 'ops@nexosdigital.test',
                'opening_balance' => '500',
                'opening_balance_direction' => 'debit',
                'is_active' => 1,
            ]);

        $customer = SalesCustomer::where('company_name', 'Nexos Digital')->first();

        $this->assertNotNull(
            $customer,
            'a welcome-email failure must never discard the customer that was created',
        );

        $response->assertRedirect(route('sales.customers.show', $customer));
        $response->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(
            500.0,
            $customer->openingBalance(),
            0.0005,
            'the opening balance and its direction must still be recorded',
        );
    }

    /**
     * @return array<string, string>
     */
    private function mailPayload(array $overrides = []): array
    {
        return array_merge([
            'mailer' => 'smtp',
            'host' => 'smtp.example.com',
            'port' => '587',
            'username' => 'no-reply@example.com',
            'password' => 'secret-value',
            'encryption' => 'tls',
            'system_from_address' => 'no-reply@nexosdigital.test',
            'reply_to_address' => 'info@nexosdigital.test',
            'personal_from_address' => 'info@nexosdigital.test',
        ], $overrides);
    }
}