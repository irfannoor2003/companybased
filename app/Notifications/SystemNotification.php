<?php

namespace App\Notifications;

use App\Support\MailIdentity;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SystemNotification extends Notification
{
    public function __construct(
        public string $title,
        public string $message,
        public string $type = 'info',
        public ?string $url = null,
        public bool $sendEmail = false,
    ) {}

    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if ($this->sendEmail && filled($notifiable->email ?? null) && (bool) settings('notifications.email_enabled', true)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->from(MailIdentity::systemFromAddress(), MailIdentity::systemFromName())
            ->subject($this->title.' — '.company_name())
            ->greeting('Hello '.($notifiable->name ?? 'there').',')
            ->line($this->message);

        $replyTo = MailIdentity::replyTo();
        if ($replyTo !== []) {
            $mail->replyTo($replyTo['address'], $replyTo['name'] ?? null);
        }

        if (filled($this->url)) {
            $mail->action('View Details', url($this->url));
        }

        return $mail;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'message' => $this->message,
            'type' => $this->type,
            'url' => $this->url,
        ];
    }
}
