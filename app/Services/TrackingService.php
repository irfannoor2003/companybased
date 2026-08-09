<?php

namespace App\Services;

use App\Models\SalesDeliveryNote;
use App\Models\SalesOrder;
use App\Models\SalesStatusEvent;
use App\Notifications\OrderTrackingNotification;
use Illuminate\Support\Str;

class TrackingService
{
    /**
     * Generate a unique public tracking code, e.g. TRK-8FK2QX.
     */
    public function generateTrackingCode(): string
    {
        do {
            $code = 'TRK-'.strtoupper(Str::random(8));
        } while (SalesOrder::query()->where('tracking_code', $code)->exists());

        return $code;
    }

    /**
     * Ensure an order has a public tracking code, generating one if needed.
     */
    public function ensureTrackingCode(SalesOrder $order): string
    {
        if ($order->tracking_code) {
            return $order->tracking_code;
        }

        $code = $this->generateTrackingCode();
        $order->update(['tracking_code' => $code]);

        return $code;
    }

    /**
     * Record a status transition and notify the customer when the order has
     * a tracking code (i.e. was confirmed).
     */
    public function recordTransition(mixed $trackable, string $toStatus, ?string $note, ?int $userId = null): void
    {
        SalesStatusEvent::create([
            'trackable_type' => $trackable->getMorphClass(),
            'trackable_id' => $trackable->id,
            'from_status' => $trackable->status,
            'to_status' => $toStatus,
            'user_id' => $userId ?? auth()->id(),
            'note' => $note,
        ]);

        $trackable->update(['status' => $toStatus]);

        if ($trackable instanceof SalesOrder) {
            $this->notifyOrder($trackable, $toStatus);
        }
    }

    /**
     * Dispatch the customer notification for an order status change, according
     * to the configured notification rules (see NotificationRule).
     */
    public function notifyOrder(SalesOrder $order, ?string $toStatus = null): void
    {
        if (! $order->tracking_code) {
            return;
        }

        $customer = $order->customer;

        if (! $customer || (! $customer->email && ! $customer->mobile && ! $customer->phone)) {
            return;
        }

        $customer->notify(new OrderTrackingNotification($order, $toStatus ?? $order->status));
    }

    /**
     * Notify the order's customer about a delivery-note status change when the
     * order has a public tracking code.
     */
    public function notifyDelivery(SalesDeliveryNote $deliveryNote, string $toStatus): void
    {
        $order = $deliveryNote->order;

        if (! $order?->tracking_code) {
            return;
        }

        $customer = $order->customer;

        if (! $customer || (! $customer->email && ! $customer->mobile && ! $customer->phone)) {
            return;
        }

        $customer->notify(new OrderTrackingNotification($order, $toStatus, 'delivery.status_changed'));
    }
}