<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * Mail settings must save -- and when they refuse, they must say why.
 *
 * Reported live on a freshly deployed site: Super Admin opened Settings -> Mail, filled
 * the form, pressed save, and nothing was stored.
 *
 * TWO COMPOUNDING CAUSES, and the visible one was not the blocking one.
 *
 * 1. THE FORM RENDERED NO ERRORS. `x-input` only prints a message when handed
 *    `:error` explicitly -- it does not read the error bag -- and the mail form
 *    passed none and rendered no summary. Every rejection was therefore invisible: the
 *    browser posted, the server redirected back, the form looked untouched, and the
 *    only conclusion available was "this does not save".
 *
 * 2. A BRAND-NEW DEPLOYMENT CANNOT SAVE SMTP SETTINGS AT ALL. The controller refuses a
 *    configuration that has no password anywhere -- not submitted, not stored, not in
 *    `.env` -- because an SMTP login with no password silently stops every queued mail.
 *    That refusal is CORRECT, and on a fresh install it is also guaranteed: there is
 *    nothing else for it to find.
 *
 * So the fix is not to weaken the guard. It is to make the guard legible.
 */
class MailSettingsSaveTest extends TestCase
{
    use SeedsDatabase;

    private ?User $superAdmin = null;

    /**
     * Memoised, not called twice.
     *
     * `userForRole()` creates the user every time it is called, so two calls in one
     * test collide on the unique email. Several tests below act as Super Admin more
     * than once.
     */
    private function superAdmin(): User
    {
        return $this->superAdmin ??= $this->userForRole('Super Admin');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'mailer' => 'smtp',
            'host' => 'smtp.repocorp.test',
            'port' => 465,
            'username' => 'info@example.com',
            'password' => '',
            'encryption' => 'ssl',
            'system_from_address' => 'noreply@example.com',
            'system_from_name' => 'Example',
            'reply_to_address' => 'hello@example.com',
            'reply_to_name' => 'Example',
            'personal_from_address' => 'info@example.com',
            'personal_from_name' => 'Example',
            'personal_use_shared_address' => '1',
            'team_username' => '',
            'team_password' => '',
            'accounts_from_address' => '',
            'accounts_from_name' => '',
            'accounts_username' => '',
            'accounts_password' => '',
            'sales_from_address' => '',
            'sales_from_name' => '',
            'sales_use_salesman_address' => '0',
            'sales_username' => '',
            'sales_password' => '',
        ], $overrides);
    }

    public function test_super_admin_can_save_mail_settings(): void
    {
        $this->actingAs($this->superAdmin())
            ->put(route('settings.mail.update'), $this->payload([
                'password' => 'a-real-password',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(
            'smtp.repocorp.test',
            Setting::get('mail.host'),
            'the submitted host was not written',
        );

        $this->assertSame(
            'info@example.com',
            Setting::get('mail.username'),
        );
    }

    public function test_saving_with_a_password_is_all_a_fresh_install_needs(): void
    {
        /**
         * The reproducible version of the report. A deployment with nothing mail-related
         * configured, filling the form in fully, must persist.
         */
        $this->assertSame('', (string) Setting::get('mail.password', ''));

        $this->actingAs($this->superAdmin())
            ->put(route('settings.mail.update'), $this->payload(['password' => 'first-time-secret']))
            ->assertSessionHasNoErrors();

        $this->assertSame('smtp.repocorp.test', Setting::get('mail.host'));
    }

    public function test_a_passwordless_smtp_login_is_refused_rather_than_saved_broken(): void
    {
        /**
         * The guard stays. An SMTP configuration with no password stops every queued
         * email silently, which is worse than refusing to save it.
         */
        $this->actingAs($this->superAdmin())
            ->put(route('settings.mail.update'), $this->payload())
            ->assertSessionHasErrors('password');

        $this->assertNotSame(
            'smtp.repocorp.test',
            Setting::get('mail.host'),
            'a refused save must not write half the form',
        );
    }

    public function test_the_mail_form_shows_why_a_save_was_refused(): void
    {
        /**
         * The regression pin for the actual defect.
         *
         * Before this, the save failed and the operator saw nothing at all. Asserting
         * the message is on the page is what stops it going back to being invisible --
         * the failure is still there, but it can no longer be silent.
         */
        /**
         * Chained with `followingRedirects()` because that is what a browser does, and
         * because flashed errors live for exactly one subsequent request. Issuing the
         * GET separately lets the flash age out, and the assertion then fails for a
         * reason that has nothing to do with the bug being pinned.
         */
        $this->actingAs($this->superAdmin())
            ->from(route('settings.mail'))
            ->followingRedirects()
            ->put(route('settings.mail.update'), $this->payload())
            ->assertSee('Mail settings were not saved')
            ->assertSee('The password field is required');
    }

    public function test_a_stored_password_is_kept_when_the_box_is_left_blank(): void
    {
        /**
         * The write-only credential rule. Once a password exists, an operator editing
         * something unrelated must not have to retype it -- and must not be able to
         * blank it by leaving the box empty.
         */
        $this->actingAs($this->superAdmin())
            ->put(route('settings.mail.update'), $this->payload(['password' => 'the-first-secret']))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->superAdmin())
            ->put(route('settings.mail.update'), $this->payload([
                'password' => '',
                'host' => 'smtp.changed.test',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('smtp.changed.test', Setting::get('mail.host'));
        $this->assertNotSame('', (string) Setting::get('mail.password', ''));
    }

    public function test_a_super_admin_reaches_the_mail_screen(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('settings.mail'))
            ->assertOk();
    }

    public function test_super_admin_holds_both_mail_permissions(): void
    {
        $role = Role::where('name', 'Super Admin')->firstOrFail();

        $this->assertTrue($role->hasPermissionTo('settings.mail.view'));
        $this->assertTrue($role->hasPermissionTo('settings.mail.manage'));
    }
}
