<?php

namespace App\Mail;

use App\Support\Branding;
use App\Support\MailIdentity;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Base class for company-branded mail.
 *
 * Applies the two-address identity model and shares the Super Admin's branding
 * (company name, logo, colours) with the mail views, so every email matches the
 * look configured under Settings → Company Profile.
 *
 * Choose an identity with the fluent helpers:
 *   useSystemIdentity()   → from no-reply@, reply-to info@   (welcome, order status, alerts)
 *   usePersonalIdentity() → from info@                        (staff emailing a customer)
 */
abstract class CompanyMailable extends Mailable
{
    use Queueable, SerializesModels;

    private string $identity = 'system';

    /**
     * Send as the company: automated mail from the no-reply address, with
     * replies redirected to the team mailbox.
     */
    public function useSystemIdentity(): static
    {
        $this->identity = 'system';

        return $this;
    }

    /**
     * Send as a human: from the shared info@ mailbox, so the conversation stays
     * on the company domain and lands with the team.
     */
    public function usePersonalIdentity(?string $staffEmail = null, ?string $staffName = null): static
    {
        $this->identity = 'personal';
        $this->personalFallbackEmail = $staffEmail;
        $this->personalFallbackName = $staffName;

        return $this;
    }

    private ?string $personalFallbackEmail = null;

    private ?string $personalFallbackName = null;

    /**
     * Apply the identity to the outgoing message.
     *
     * Injected in send() rather than by overriding from()/replyTo(), because
     * those are public API on the base Mailable with a different signature.
     * Doing it here means every subclass gets the right sender for free,
     * including ones that declare their own Envelope.
     */
    private function applyIdentity(): void
    {
        if ($this->identity === 'personal') {
            $this->from(
                MailIdentity::personalFromAddress($this->personalFallbackEmail),
                MailIdentity::personalFromName((string) $this->personalFallbackName),
            );
        } else {
            $this->from(MailIdentity::systemFromAddress(), MailIdentity::systemFromName());
        }

        $replyTo = MailIdentity::replyTo();

        if ($replyTo !== []) {
            $this->replyTo($replyTo['address'], $replyTo['name'] ?? null);
        }
    }

    public function send($mailer)
    {
        $this->applyIdentity();

        return parent::send($mailer);
    }

    /**
     * Data every branded mail view can rely on.
     *
     * @return array<string, mixed>
     */
    protected function brandData(): array
    {
        $brand = Branding::payload();

        return [
            'brand' => $brand,
            'companyName' => $brand['companyName'],
        ];
    }
}
