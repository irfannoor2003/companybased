<?php

namespace App\Notifications;

use App\Models\InventoryItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to whoever manages inventory when a stock level drops to or below the
 * item's reorder level.
 *
 * Always uses the automated (no-reply) identity, since no human pressed send.
 */
class LowStockAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public InventoryItem $item,
        public float $onHand,
        public float $threshold,
    ) {}

    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if ((bool) settings('notifications.email_enabled', true) && filled($notifiable->email)) {
            array_unshift($channels, 'mail');
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = $this->item->product?->name ?? 'Item #'.$this->item->id;
        $sku = $this->item->product?->sku;
        $reorder = (float) $this->item->reorder_quantity;

        $fmt = fn (float $value): string => rtrim(rtrim(number_format($value, 3), '0'), '.');

        // MailMessage has no ->table(), so the figures are laid out as lines.
        $message = (new MailMessage)
            ->subject('Low stock: '.$name)
            ->greeting('Low stock alert')
            ->line("**{$name}**".($sku ? " (SKU {$sku})" : '').' has fallen to its reorder level.')
            ->line('On hand: **'.$fmt($this->onHand).'**')
            ->line('Reorder level: **'.$fmt($this->threshold).'**')
            ->line('Suggested order: **'.($reorder > 0 ? $fmt($reorder) : '—').'**');

        return $message
            ->action('Review stock', route('inventory.items.show', $this->item))
            ->line('You will be alerted again once this item is restocked and falls below its reorder level a second time.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Low stock: '.($this->item->product?->name ?? 'Item #'.$this->item->id),
            'message' => sprintf(
                'On hand %s is at or below the reorder level of %s.',
                rtrim(rtrim(number_format($this->onHand, 3), '0'), '.'),
                rtrim(rtrim(number_format($this->threshold, 3), '0'), '.'),
            ),
            'type' => 'warning',
            'url' => route('inventory.items.show', $this->item),
        ];
    }
}
