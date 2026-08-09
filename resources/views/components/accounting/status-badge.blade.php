@props(['status' => 'draft'])

@php
    $map = [
        'draft' => ['neutral', 'Draft'],
        'posted' => ['success', 'Posted'],
        'void' => ['danger', 'Void'],
        'pending' => ['warning', 'Pending'],
        'approved' => ['success', 'Approved'],
        'rejected' => ['danger', 'Rejected'],
        'reimbursed' => ['info', 'Reimbursed'],
        'open' => ['info', 'Open'],
        'partially_paid' => ['warning', 'Partially paid'],
        'paid' => ['success', 'Paid'],
        'filed' => ['info', 'Filed'],
        'active' => ['success', 'Active'],
        'closed' => ['neutral', 'Closed'],
    ];
    [$color, $label] = $map[$status] ?? ['neutral', ucfirst(str_replace('_', ' ', $status))];
@endphp

<x-badge :color="$color" dot>{{ $label }}</x-badge>
