<?php

/*
|--------------------------------------------------------------------------
| Status Badge Presentation
|--------------------------------------------------------------------------
|
| There were seven near-identical status-badge blade components, one per module,
| each carrying its own inline $map of status => [colour, label]. They had
| already drifted: "cancelled" rendered as danger in sales, suppliers and
| banking but neutral in visits, and "pending" was neutral in sales/suppliers but
| warning in accounting, employees and inventory. A status colour therefore
| depended on which module folder a view happened to live in.
|
| The maps live here so the colours are one edit, not seven. They are kept
| per-module, rather than merged, precisely to preserve those existing
| differences rather than silently changing what users see.
|
| Colour vocabulary: neutral, info, success, warning, danger, primary.
|
*/

return [

    'sales' => [
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
    ],

    'suppliers' => [
        'draft' => ['neutral', 'Draft'],
        'sent' => ['info', 'Sent'],
        'accepted' => ['success', 'Accepted'],
        'rejected' => ['danger', 'Rejected'],
        'converted' => ['success', 'Converted'],
        'confirmed' => ['info', 'Confirmed'],
        'partial_received' => ['warning', 'Partially received'],
        'received' => ['success', 'Received'],
        'completed' => ['success', 'Completed'],
        'cancelled' => ['danger', 'Cancelled'],
        'partially_paid' => ['warning', 'Partially paid'],
        'paid' => ['success', 'Paid'],
        'overdue' => ['danger', 'Overdue'],
        'pending' => ['neutral', 'Pending'],
        'active' => ['success', 'Active'],
        'inactive' => ['neutral', 'Inactive'],
    ],

    'accounting' => [
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
    ],

    'banking' => [
        'draft' => ['neutral', 'Draft'],
        'completed' => ['success', 'Completed'],
        'cancelled' => ['danger', 'Cancelled'],
        'active' => ['success', 'Active'],
        'inactive' => ['neutral', 'Inactive'],
    ],

    'employees' => [
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
    ],

    'visits' => [
        'draft' => ['neutral', 'Draft'],
        'pending' => ['warning', 'Pending'],
        'started' => ['info', 'In progress'],
        'completed' => ['success', 'Completed'],
        // Deliberately neutral here but danger elsewhere; preserved as-is.
        'cancelled' => ['neutral', 'Cancelled'],
        'attended' => ['info', 'Attended'],
        'closed_deal' => ['success', 'Closed deal'],
        'rescheduled' => ['warning', 'Rescheduled'],
        'no_contact' => ['danger', 'No contact'],
        'not_interested' => ['neutral', 'Not interested'],
    ],

    'inventory' => [
        'active' => ['success', 'Active'],
        'inactive' => ['neutral', 'Inactive'],
        'draft' => ['neutral', 'Draft'],
        'pending' => ['warning', 'Pending'],
        'in_progress' => ['warning', 'In progress'],
        'in_transit' => ['info', 'In transit'],
        'arrived' => ['success', 'Arrived'],
        'completed' => ['success', 'Completed'],
        'approved' => ['success', 'Approved'],
        'cancelled' => ['danger', 'Cancelled'],
        'low' => ['danger', 'Low'],
        'out' => ['danger', 'Out'],
        'ok' => ['success', 'OK'],
    ],

];
