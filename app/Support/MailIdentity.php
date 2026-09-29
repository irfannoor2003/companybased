<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Resolves who outgoing email comes from, and where replies should land.
 *
 * Two distinct identities are supported, because mixing them is how you end up
 * either losing customer replies in a black-hole mailbox or accidentally
 * sending "personal" mail from a no-reply address:
 *
 *  - SYSTEM (transactional) — welcome mails, order status updates, low stock
 *    alerts, password resets. Sent from a no-reply@ address because nobody is
 *    watching it during a send, but its Reply-To points at the real mailbox so
 *    a customer who does hit reply reaches a human.
 *
 *  - PERSONAL — a human member of staff emailing a customer from the portal
 *    (CustomerEmail). Sent from the shared info@ address so the conversation
 *    stays on the company domain and lands in the team inbox.
 *
 * Everything is resolved from the `settings` table (edited by Super Admin under
 * Settings → Mail Server), falling back to the MAIL_* environment values so a
 * fresh install still works before anyone visits that screen.
 */
final class MailIdentity
{
    private const SYSTEM_PREFIX = 'mail.system';

    private const PERSONAL_PREFIX = 'mail.personal';

    private const REPLY_TO_PREFIX = 'mail.reply_to';

    /**
     * From address for automated / transactional mail. No-reply by default.
     */
    public static function systemFromAddress(): string
    {
        return (string) (settings(self::SYSTEM_PREFIX.'_from_address') ?: self::guessNoReplyAddress());
    }

    /**
     * Display name for automated mail, e.g. "Nexos Digital".
     */
    public static function systemFromName(): string
    {
        return (string) (settings(self::SYSTEM_PREFIX.'_from_name') ?: company_name());
    }

    /**
     * Where replies to automated mail should be delivered. Defaults to the
     * shared info@ mailbox rather than the no-reply sender.
     */
    public static function replyToAddress(): ?string
    {
        $address = settings(self::REPLY_TO_PREFIX.'_address') ?: self::guessInfoAddress();

        return filled($address) ? (string) $address : null;
    }

    public static function replyToName(): ?string
    {
        $name = settings(self::REPLY_TO_PREFIX.'_name');

        return filled($name) ? (string) $name : null;
    }

    /**
     * Whether staff-sent personal email should use the shared info@ identity.
     * When disabled, mail from the portal is sent as the individual staff
     * member using the address of their own user account.
     */
    public static function usesSharedPersonalAddress(): bool
    {
        $configured = settings(self::PERSONAL_PREFIX.'_use_shared_address');

        // Default to the shared mailbox: a reply to a staff member should not
        // fail just because that individual later leaves the company.
        return $configured === null ? true : filter_var($configured, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * From address for human-sent email from the portal.
     */
    public static function personalFromAddress(?string $fallback = null): string
    {
        if (! self::usesSharedPersonalAddress() && filled($fallback)) {
            return $fallback;
        }

        return (string) (settings(self::PERSONAL_PREFIX.'_from_address') ?: self::guessInfoAddress() ?: $fallback ?: config('mail.from.address'));
    }

    public static function personalFromName(string $fallback = ''): string
    {
        if (! self::usesSharedPersonalAddress() && filled($fallback)) {
            return $fallback;
        }

        return (string) (settings(self::PERSONAL_PREFIX.'_from_name') ?: company_name());
    }

    /**
     * The reply-to pair, or an empty array when no mailbox is configured.
     *
     * @return array<string, string>
     */
    public static function replyTo(): array
    {
        $address = self::replyToAddress();

        if (! $address) {
            return [];
        }

        $name = self::replyToName();

        return $name ? ['address' => $address, 'name' => $name] : ['address' => $address];
    }

    /**
     * Derive no-reply@<domain> from the configured MAIL_FROM_ADDRESS, so the
     * default works without the Super Admin filling anything in.
     */
    private static function guessNoReplyAddress(): string
    {
        $domain = self::domainFromConfiguredSender();

        return $domain ? 'no-reply@'.$domain : (string) config('mail.from.address');
    }

    /**
     * Derive info@<domain> the same way.
     */
    private static function guessInfoAddress(): ?string
    {
        $domain = self::domainFromConfiguredSender();

        return $domain ? 'info@'.$domain : null;
    }

    private static function domainFromConfiguredSender(): ?string
    {
        $configured = (string) (settings(self::SYSTEM_PREFIX.'_from_address') ?: config('mail.from.address'));
        $domain = Str::after($configured, '@');

        return str_contains($domain, '.') ? $domain : null;
    }
}
