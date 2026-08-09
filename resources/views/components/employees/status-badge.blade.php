@props(['status' => 'draft'])

@php
    $map = [
        'draft' => ['neutral', 'Draft'],
        'submitted' => ['info', 'Submitted'],
        'paid' => ['success', 'Paid'],
        'void' => ['danger', 'Void'],
        'pending' => ['warning', 'Pending'],
        'present' => ['success', 'Present'],
        'late' => ['warning', 'Late'],
        'short_leave' => ['info', 'Short leave'],
        'half_day' => ['warning', 'Half day'],
        'absent' => ['danger', 'Absent'],
        'active' => ['success', 'Active'],
        'on_leave' => ['warning', 'On leave'],
        'terminated' => ['danger', 'Terminated'],
        'manual' => ['info', 'Manual'],
        'qr' => ['primary', 'QR'],
        'fingerprint' => ['info', 'Fingerprint'],
    ];
    [$color, $label] = $map[$status] ?? ['neutral', ucfirst(str_replace('_', ' ', $status))];
@endphp

<x-badge :color="$color" dot>{{ $label }}</x-badge>
