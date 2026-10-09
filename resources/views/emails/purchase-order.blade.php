<x-mail::message>
# Purchase Order {{ $order->number }}

Dear {{ $supplier->contact_name ?: $supplier->company_name }},

Please find attached our official purchase order **{{ $order->number }}** dated **{{ $order->order_date?->format('M d, Y') ?? 'N/A' }}** for the amount of **{{ money($order->total, $order->currency) }}**.

@if (filled($customMessage))
<x-mail::panel>
{!! nl2br(e($customMessage)) !!}
</x-mail::panel>
@endif

### Purchase Order Summary
- **Order Number:** {{ $order->number }}
- **Order Date:** {{ $order->order_date?->format('M d, Y') ?? 'N/A' }}
- **Delivery Date:** {{ $order->delivery_date?->format('M d, Y') ?? 'As arranged' }}
- **Total:** {{ money($order->total, $order->currency) }}

Please review the attached PDF and confirm acceptance and delivery timeframe.

Regards,<br>
**{{ $brand['companyName'] }} Purchasing**<br>
{{ company_name() }}
</x-mail::message>
