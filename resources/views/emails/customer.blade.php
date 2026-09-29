<x-mail::message>
# Hello {{ $customer->contact_name ?: $customer->company_name }}

{!! nl2br(e($body)) !!}

 Regards,
<strong>{{ $sender->name }}</strong>
{{ company_name() }}
{{ $sender->email }}

<br>
<small style="color:#718096;">This email was sent by {{ $sender->name }} from {{ company_name() }}.</small>
</x-mail::message>
