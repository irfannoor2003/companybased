<x-mail::message>
# Welcome to {{ company_name() }}, {{ $customer->contact_name ?: $customer->company_name }}

Thank you for your business. Your customer account is now set up with us.

@if ($customer->company_name)
**Account name:** {{ $customer->company_name }}
@endif
@if ($customer->tax_number)
**Tax number:** {{ $customer->tax_number }}
@endif

Our team can now raise quotes, orders and invoices against your account, and you will receive an email whenever an order changes status.

If anything above looks wrong, or you would like to update your contact details, simply reply to this email and we will take care of it.

Regards,<br>
{{ company_name() }}

<x-mail::panel>
Questions? Reply to this email and our team will get back to you.
</x-mail::panel>
</x-mail::message>
