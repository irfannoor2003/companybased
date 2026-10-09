<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\Branding;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The mail panel must actually render every credential field it validates.
 *
 * Both regressions here were silent: the form validated `password` and
 * `username` server-side, so saving appeared to work, but the inputs were never
 * emitted. Blade emits a component tag containing an `@required(...)`
 * directive as literal text instead of compiling it, so both fields vanished
 * from the page while the tests that POST to them kept passing.
 */
class MailCredentialsFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Mail::fake();
    }

    private function superAdmin(): User
    {
        return User::where('email', 'superadmin@nexosdigital.test')->firstOrFail();
    }

    private function html(): string
    {
        return $this->actingAs($this->superAdmin())->get('/settings/mail')->assertOk()->getContent();
    }

    public function test_the_smtp_username_and_password_fields_are_rendered(): void
    {
        $html = $this->html();

        $this->assertMatchesRegularExpression('/<input[^>]*name="username"/', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*name="password"/', $html);
    }

    public function test_the_password_field_is_a_masked_input(): void
    {
        $this->assertMatchesRegularExpression(
            '/<input[^>]*name="password"[^>]*type="password"|type="password"[^>]*name="password"/',
            $this->html()
        );
    }

    public function test_the_second_mailbox_login_fields_are_rendered(): void
    {
        $html = $this->html();

        $this->assertMatchesRegularExpression('/<input[^>]*name="team_username"/', $html);
        $this->assertMatchesRegularExpression(
            '/<input[^>]*name="team_password"[^>]*type="password"|type="password"[^>]*name="team_password"/',
            $html
        );
    }

    /**
     * A field that is absent from the page but present in the validation rules
     * is the actual defect this guards, so assert on the raw tag rather than a
     * friendly label that could be reworded.
     */
    public function test_no_component_tag_is_leaked_as_literal_text(): void
    {
        $this->assertStringNotContainsString('<x-input', $this->html());
        $this->assertStringNotContainsString('<x-select', $this->html());
    }

    public function test_the_password_is_required_when_none_is_stored(): void
    {
        $this->assertMatchesRegularExpression(
            '/<input[^>]*name="password"[^>]*required|required[^>]*name="password"/',
            $this->html()
        );
    }

    public function test_the_password_is_optional_once_one_is_stored(): void
    {
        Setting::set('mail.password', 'stored-secret');
        Setting::flushCache();

        $html = $this->html();

        $this->assertDoesNotMatchRegularExpression('/<input[^>]*name="password"[^>]*required/', $html);
        // ...and the stored value itself must never be sent to the browser.
        $this->assertStringNotContainsString('stored-secret', $html);
    }

    public function test_the_test_email_template_renders(): void
    {
        // The original failure was "No hint path defined for [mail]": the
        // x-mail::* namespace is only registered inside the Markdown renderer,
        // so rendering the template through it is the part that must work.
        $html = app(Markdown::class)
            ->render('emails.test-mail', ['brand' => Branding::payload()])
            ->toHtml();

        $this->assertStringContainsString('Mail server test', $html);
        $this->assertStringNotContainsString('<x-mail:', $html);
    }

    public function test_the_test_email_endpoint_sends_without_error(): void
    {
        $response = $this->actingAs($this->superAdmin())
            ->post('/settings/mail/test', ['test_email' => 'someone@example.test']);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $message = session('toasts')[0]['message'] ?? '';

        // The toast names the mailbox the test mail was sent from, so the
        // assertion matches the shipped copy rather than an older wording.
        $this->assertStringContainsString('Test email', $message);
        $this->assertStringContainsString('someone@example.test', $message);
    }

    public function test_an_smtp_authentication_failure_is_reported_without_leaking_the_password(): void
    {
        Setting::set('mail.password', 'super-secret-password');
        Setting::flushCache();

        // Simulate Hostinger rejecting the login the way Symfony's SMTP
        // transport does, which restates the username in its message.
        // Swap the facade itself so the cached 'mail.manager' instance created
        // by Mail::fake() in setUp() cannot swallow the call.
        Mail::swap(new class
        {
            public function html($html, $callback): void
            {
                throw new \RuntimeException('Failed to authenticate on smtp.hostinger.com with username no-reply@nexosdigital.com: 535 5.7.8 Authentication credentials invalid');
            }

            public function __call($method, $parameters)
            {
                return $this;
            }
        });

        $response = $this->actingAs($this->superAdmin())
            ->post('/settings/mail/test', ['test_email' => 'someone@example.test']);

        $response->assertRedirect();

        $message = session('toasts')[0]['message'] ?? '';

        $this->assertStringContainsString('rejected the username or password', $message);
        $this->assertStringNotContainsString('super-secret-password', $message);
        $this->assertStringNotContainsString('535 5.7.8', $message);
    }
}
