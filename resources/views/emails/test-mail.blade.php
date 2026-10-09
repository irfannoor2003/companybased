<x-mail::message>
# Mail server test

This is a test message from **{{ $brand['companyName'] }}** to confirm your outgoing mail configuration is working.

If you are reading this, the SMTP server accepted and delivered the message.

<x-mail::panel>
Sent using the **{{ ucfirst($identity ?? 'system') }}** identity (From: {{ $fromAddress ?? 'default sender' }}). Replies are routed to your configured Reply-To mailbox.
</x-mail::panel>

Regards,<br>
{{ $brand['companyName'] }}
</x-mail::message>
