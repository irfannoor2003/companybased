@props(['status' => 'draft'])

@php
    $map = [
        'draft' => ['neutral', 'Draft'],
        'pending' => ['warning', 'Pending'],
        'started' => ['info', 'In progress'],
        'completed' => ['success', 'Completed'],
        'cancelled' => ['neutral', 'Cancelled'],
        'attended' => ['info', 'Attended'],
        'closed_deal' => ['success', 'Closed deal'],
        'rescheduled' => ['warning', 'Rescheduled'],
        'no_contact' => ['danger', 'No contact'],
        'not_interested' => ['neutral', 'Not interested'],
    ];
    [$color, $label] = $map[$status] ?? ['neutral', ucfirst(str_replace('_', ' ', $status))];
@endphp

<x-badge :color="$color" dot>{{ $label }}</x-badge>