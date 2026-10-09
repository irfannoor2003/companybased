<x-mail::message>
# Quotation {{ $quote->number }}

Dear {{ $customer->contact_name ?: $customer->company_name }},

Thank you for your interest. Please find attached quotation **{{ $quote->number }}** dated **{{ $quote->issue_date?->format('M d, Y') ?? 'N/A' }}** for the total amount of **{{ money($quote->total, $quote->currency) }}**.

@if (filled($customMessage))
<x-mail::panel>
{!! nl2br(e($customMessage)) !!}
</x-mail::panel>
@endif

### Quotation Details
- **Quote Number:** {{ $quote->number }}
- **Date:** {{ $quote->issue_date?->format('M d, Y') ?? 'N/A' }}
- **Expiry Date:** {{ $quote->expiry_date?->format('M d, Y') ?? '30 days' }}
- **Total Amount:** {{ money($quote->total, $quote->currency) }}

The detailed itemized quotation is attached as a PDF. Please let us know if you would like to proceed or if you require any adjustments.

Regards,<br>
@if ($salesman)
**{{ $salesman->name }}**<br>
Sales Representative<br>
@endif
**{{ $brand['companyName'] }}**
</x-mail::message>
