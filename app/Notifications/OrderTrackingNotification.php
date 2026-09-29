<?php

namespace App\Notifications;

use App\Models\SalesCustomer;
use App\Models\SalesOrder;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the customer their order moved forward — confirmation, packing,
 * dispatch, delivery or cancellation — in the style of a store order
 * confirmation mail, including a live tracking link.
 *
 * Delivery-note events reuse this class with $event = 'delivery.status_changed';
 * the copy is keyed off the resulting status either way.
 */
class OrderTrackingNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public SalesOrder $order,
        public string $toStatus,
        public string $event = 'order.status_changed',
    ) {}

    /**
     * Determine which channels to send through based on the company's
     * notification rules (see app/Services/NotificationService).
     */
    public function via(object $notifiable): array
    {
        return app(NotificationService::class)->channelsFor($this->order, $this->event, $notifiable);
    }

    public function toMail(SalesCustomer $notifiable): MailMessage
    {
        $order = $this->order;
        $copy = $this->copyFor($this->toStatus);

        $message = (new MailMessage)
            ->subject($copy['subject'])
            ->greeting('Hello '.($notifiable->contact_name ?: $notifiable->company_name).',')
            ->line($copy['intro']);

        // Show the fulfilment summary for every status past confirmation.
        // MailMessage has no ->table(), so the itemisation is rendered as lines.
        if (in_array($this->toStatus, ['packed', 'shipped', 'delivered'], true)) {
            $message = $message->line('**Order summary**');

            foreach ($this->fulfilmentRows() as [$name, $qty, $total]) {
                $message = $message->line("- {$name} — {$qty} × {$total}");
            }

            $message = $message->line('**Total: '.money($this->order->total, $this->order->currency).'**');
        }

        foreach ($copy['lines'] as $line) {
            $message = $message->line($line);
        }

        return $message
            ->action($copy['action'], url('/track/'.$order->tracking_code))
            ->line('Thank you for your business.');
    }

    /**
     * Per-status subject / intro / body / button copy.
     *
     * @return array{subject: string, intro: string, lines: array<int, string>, action: string}
     */
    private function copyFor(string $status): array
    {
        $number = $this->order->number;

        return match ($status) {
            'confirmed' => [
                'subject' => "Your order {$number} is confirmed",
                'intro' => 'Thank you for your order. We have received it and it is now confirmed.',
                'lines' => [
                    "We are getting your order **{$number}** ready. You can follow its progress at any time using the tracking link below.",
                    'If anything needs to change, reply to this email and we will sort it out.',
                ],
                'action' => 'View your order',
            ],
            'packed' => [
                'subject' => "Your order {$number} has been packed",
                'intro' => 'Good news — your order is packed and ready to go.',
                'lines' => [
                    "Everything in order **{$number}** has been picked, checked and packed.",
                    'It will be handed over to our delivery partner shortly. Keep an eye on the tracking link for the next update.',
                ],
                'action' => 'Track your delivery',
            ],
            'shipped' => [
                'subject' => "Your order {$number} is on its way",
                'intro' => 'Your order is out for delivery.',
                'lines' => [
                    "Order **{$number}** has left our warehouse and is on its way to you.",
                    'Use the tracking link below for live status updates until it arrives.',
                ],
                'action' => 'Track your delivery',
            ],
            'delivered' => [
                'subject' => "Your order {$number} has been delivered",
                'intro' => 'Your order has arrived. We hope you are happy with it.',
                'lines' => [
                    "Order **{$number}** has been marked as delivered.",
                    'If something is missing or damaged, please reply to this email within 7 days and we will put it right.',
                ],
                'action' => 'View your order',
            ],
            'cancelled' => [
                'subject' => "Your order {$number} has been cancelled",
                'intro' => 'We are sorry, but your order has been cancelled.',
                'lines' => [
                    "Order **{$number}** is no longer going ahead.",
                    'If you were charged for it, any amount paid will be refunded to your original payment method. Nothing further is needed from you.',
                ],
                'action' => 'View your order',
            ],
            default => [
                'subject' => "Order {$number} is now ".ucfirst(str_replace('_', ' ', $status)),
                'intro' => 'Your order has been updated.',
                'lines' => [
                    "Order **{$number}** is now **{$status}**.",
                    'Follow the delivery below to track its progress.',
                ],
                'action' => 'View your order',
            ],
        };
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function fulfilmentRows(): array
    {
        return $this->order->items
            ->map(fn ($item): array => [
                $item->product?->name ?? $item->description ?? 'Item',
                (string) $item->qty,
                money($item->line_total, $this->order->currency),
            ])
            ->values()
            ->all();
    }

    public function toArray(SalesCustomer $notifiable): array
    {
        return [
            'order_id' => $this->order->id,
            'order_number' => $this->order->number,
            'tracking_code' => $this->order->tracking_code,
            'status' => $this->toStatus,
            'event' => $this->event,
            'tracking_url' => url('/track/'.$this->order->tracking_code),
        ];
    }
}
