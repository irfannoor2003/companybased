<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class SystemNotification extends Notification
{
    public function __construct(
        public string $title,
        public string $message,
        public string $type = 'info',
        public ?string $url = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
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
