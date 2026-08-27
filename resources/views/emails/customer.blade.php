<x-mail::message>
# Hello {{ $customer->contact_name ?: $customer->company_name }}

{!! nl2br(e($body)) !!}

 Regards,
<strong>{{ $sender->name }}</strong>
{{ company_name() }}
{{ $sender->email }}

<x-mail::subfooter>
This email was sent by {{ $sender->name }} from {{ company_name() }}.
</x-mail::subfooter>
</x-mail::message>
