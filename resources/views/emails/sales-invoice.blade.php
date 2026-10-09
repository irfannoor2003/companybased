<x-mail::message>
# Invoice {{ $invoice->number }}

Dear {{ $customer->contact_name ?: $customer->company_name }},

Please find attached your invoice **{{ $invoice->number }}** dated **{{ $invoice->issue_date?->format('M d, Y') ?? 'N/A' }}** for the amount of **{{ money($invoice->total, $invoice->currency) }}**.

@if (filled($customMessage))
<x-mail::panel>
{!! nl2br(e($customMessage)) !!}
</x-mail::panel>
@endif

### Invoice Summary
- **Invoice Number:** {{ $invoice->number }}
- **Issue Date:** {{ $invoice->issue_date?->format('M d, Y') ?? 'N/A' }}
- **Due Date:** {{ $invoice->due_date?->format('M d, Y') ?? 'Due on receipt' }}
- **Amount Due:** {{ money($invoice->total, $invoice->currency) }}

The full PDF invoice has been attached to this email for your records.

If you have any questions or require remittance information, please reply directly to this email.

Regards,<br>
**{{ $brand['companyName'] }} Accounts**<br>
{{ company_name() }}
</x-mail::message>
