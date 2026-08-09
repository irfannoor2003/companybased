<?php

namespace App\Services;

use App\Models\NotificationRule;
use App\Models\SalesOrder;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Channel-agnostic notification dispatcher. Rules are stored as data
 * (notification_rules table + settings.notifications.* provider config),
 * so which channels fire for which event is config, not code.
 */
class NotificationService
{
    public const CHANNELS = [
        'mail' => 'Email',
        'sms' => 'SMS',
        'whatsapp' => 'WhatsApp',
        'database' => 'In-app',
    ];

    /**
     * Channels enabled for an event, given the rules and provider settings.
     */
    public function channelsFor(mixed $trackable, string $event, object $notifiable): array
    {
        $rule = NotificationRule::forEvent($event);

        if (! $rule) {
            return $this->fallbackChannels($notifiable);
        }

        $channels = $rule->channels ?: [];

        $mailEnabled = (bool) settings('notifications.email_enabled', true);

        return array_values(array_filter($channels, function (string $channel) use ($notifiable, $mailEnabled) {
            if ($channel === 'mail') {
                return $mailEnabled && $this->hasMailAddress($notifiable);
            }

            if ($channel === 'sms') {
                return (bool) settings('notifications.sms_enabled', false) && $this->hasPhone($notifiable);
            }

            if ($channel === 'whatsapp') {
                return (bool) settings('notifications.whatsapp_enabled', false) && $this->hasPhone($notifiable);
            }

            return true;
        }));
    }

    /**
     * Notify a notifiable with a notification, running any pre/post hooks
     * (logging, provider fallback) around the dispatch.
     */
    public function notify(object $notifiable, Notification $notification): void
    {
        try {
            $notifiable->notify($notification);
        } catch (\Throwable $e) {
            Log::warning('Notification dispatch failed', [
                'notifiable' => get_class($notifiable),
                'notification' => get_class($notification),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * When no rule exists, default to email-only for email-able notifiables.
     */
    private function fallbackChannels(object $notifiable): array
    {
        $channels = [];

        if ((bool) settings('notifications.email_enabled', true) && $this->hasMailAddress($notifiable)) {
            $channels[] = 'mail';
        }

        return $channels ?: ['database'];
    }

    private function hasMailAddress(object $notifiable): bool
    {
        return method_exists($notifiable, 'routeNotificationFor') && filled($notifiable->routeNotificationFor('mail'));
    }

    private function hasPhone(object $notifiable): bool
    {
        return property_exists($notifiable, 'mobile') || property_exists($notifiable, 'phone');
    }
}
