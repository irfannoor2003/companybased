<?php

namespace App\Mail;

use App\Models\SalesCustomer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sent to a customer the moment their account is created, so they know they
 * are now on file and how to reach us.
 *
 * System identity: no-reply@ sender, reply-to routed to the team mailbox.
 */
class CustomerWelcome extends CompanyMailable implements ShouldQueue
{
    public function __construct(
        public SalesCustomer $customer,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Welcome to '.company_name().', '.$this->salutation(),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.customer-welcome',
            with: array_merge($this->brandData(), ['customer' => $this->customer]),
        );
    }

    public function attachments(): array
    {
        return [];
    }

    private function salutation(): string
    {
        return $this->customer->contact_name ?: $this->customer->company_name;
    }
}
