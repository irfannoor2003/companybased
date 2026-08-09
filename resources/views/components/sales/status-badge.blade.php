@props(['status' => 'draft'])

@php
    $map = [
        'draft' => ['neutral', 'Draft'],
        'sent' => ['info', 'Sent'],
        'accepted' => ['success', 'Accepted'],
        'rejected' => ['danger', 'Rejected'],
        'converted' => ['success', 'Converted'],
        'confirmed' => ['info', 'Confirmed'],
        'packed' => ['info', 'Packed'],
        'shipped' => ['warning', 'Shipped'],
        'delivered' => ['success', 'Delivered'],
        'cancelled' => ['danger', 'Cancelled'],
        'partially_paid' => ['warning', 'Partially paid'],
        'paid' => ['success', 'Paid'],
        'overdue' => ['danger', 'Overdue'],
        'pending' => ['neutral', 'Pending'],
        'active' => ['success', 'Active'],
        'inactive' => ['neutral', 'Inactive'],
    ];
    [$color, $label] = $map[$status] ?? ['neutral', ucfirst(str_replace('_', ' ', $status))];
@endphp

<x-badge :color="$color" dot>{{ $label }}</x-badge>
