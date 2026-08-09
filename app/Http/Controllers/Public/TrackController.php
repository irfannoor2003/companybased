<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\SalesOrder;
use Illuminate\View\View;

class TrackController extends Controller
{
    public function show(string $code): View
    {
        $order = SalesOrder::query()
            ->with(['customer', 'items', 'deliveryNotes', 'statusEvents'])
            ->where('tracking_code', $code)
            ->first();

        if (! $order) {
            abort(404, 'Tracking code not found.');
        }

        $invoiceTotal = (float) $order->invoices()->sum('total');
        $paid = (float) $order->invoices()->with('payments')->get()
            ->reduce(fn (float $carry, $invoice) => $carry + (float) $invoice->payments()->sum('amount'), 0.0);

        $paymentStatus = 'Pending';
        if ($invoiceTotal > 0 && $paid >= $invoiceTotal) {
            $paymentStatus = 'Paid';
        } elseif ($paid > 0) {
            $paymentStatus = 'Partial';
        }

        return view('public.track', compact('order', 'paymentStatus'));
    }
}