<?php

namespace App\Mail;

use App\Models\Subscription;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class SubscriptionExpiringSoon extends CompanyMailable implements ShouldQueue
{
    public function __construct(
        public Subscription $subscription,
        public int $daysRemaining,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your package is expiring soon — action required',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.subscription-expiring-soon',
            with: array_merge($this->brandData(), [
                'subscription' => $this->subscription,
                'daysRemaining' => $this->daysRemaining,
            ]),
        );
    }
}
