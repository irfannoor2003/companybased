<?php

namespace App\Mail;

use App\Models\SalesCustomer;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A staff member emailing a customer directly from the customer record.
 *
 * Personal identity: sent from the shared info@ mailbox (or, if the company
 * prefers, from that individual) rather than the no-reply system address, because
 * a real person is starting a real conversation and expects a reply.
 */
class CustomerEmail extends CompanyMailable implements ShouldQueue
{
    public function __construct(
        public SalesCustomer $customer,
        public User $sender,
        public string $subjectLine,
        public string $body,
    ) {
        $this->usePersonalIdentity($sender->email, $sender->displayName());
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.customer',
            with: array_merge($this->brandData(), [
                'customer' => $this->customer,
                'sender' => $this->sender,
                'body' => $this->body,
            ]),
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
