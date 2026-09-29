<?php

namespace App\Mail;

use App\Models\Employee;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sent to a newly created employee. When HR also provisioned a login account the
 * temporary password is included so they can sign in immediately.
 *
 * System identity: no-reply@ sender, reply-to routed to the team mailbox.
 */
class EmployeeWelcome extends CompanyMailable implements ShouldQueue
{
    public function __construct(
        public Employee $employee,
        public ?string $temporaryPassword = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Welcome to '.company_name().', '.$this->employee->first_name,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.employee-welcome',
            with: array_merge($this->brandData(), [
                'employee' => $this->employee,
                'temporaryPassword' => $this->temporaryPassword,
                'loginUrl' => route('login'),
            ]),
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
