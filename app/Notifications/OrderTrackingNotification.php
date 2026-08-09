<?php

namespace App\Notifications;

use App\Models\SalesCustomer;
use App\Models\SalesOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderTrackingNotification extends Notification
{
    use Queueable;

    public function __construct(
        public SalesOrder $order,
        public string $toStatus,
        public string $event = 'order.status_changed',
    ) {
    }

    /**
     * Determine which channels to send through based on the company's
     * notification rules (see app/Services/NotificationService).
     */
    public function via(object $notifiable): array
    {
        return app(\App\Services\NotificationService::class)->channelsFor($this->order, $this->event, $notifiable);
    }

    public function toMail(SalesCustomer $notifiable): MailMessage
    {
        $url = url('/track/'.$this->order->tracking_code);

        return (new MailMessage)
            ->subject('Order '.$this->order->number.' is now '.ucfirst($this->toStatus))
            ->greeting('Hello '.($notifiable->contact_name ?: $notifiable->company_name).',')
            ->line('Your order **'.$this->order->number.'** has been updated to: **'.ucfirst($this->toStatus).'**.')
            ->line('Follow the delivery below to track its progress.')
            ->action('Track your order', $url)
            ->line('Thank you for your business.');
    }

    public function toArray(SalesCustomer $notifiable): array
    {
        return [
            'order_id' => $this->order->id,
            'order_number' => $this->order->number,
            'tracking_code' => $this->order->tracking_code,
            'status' => $this->toStatus,
            'tracking_url' => url('/track/'.$this->order->tracking_code),
        ];
    }
}
