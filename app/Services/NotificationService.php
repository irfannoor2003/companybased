<?php

namespace App\Services;

use App\Models\NotificationRule;
use App\Models\User;
use App\Notifications\SystemNotification;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as NotificationFacade;

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
    public function notifyStaff(string $permission, string $title, string $message, string $type = 'info', ?string $url = null, ?int $exceptUserId = null): void
    {
        // Resolve the permission in SQL rather than loading every user and
        // running a permission check on each one.
        $users = User::query()->permission($permission);

        if ($exceptUserId !== null) {
            $users->whereKeyNot($exceptUserId);
        }

        $users = $users->get();

        if ($users->isEmpty()) {
            return;
        }

        try {
            NotificationFacade::send($users, new SystemNotification($title, $message, $type, $url));
        } catch (\Throwable $e) {
            $this->logFailure('staff', $title, $e);
        }
    }

    /**
     * Notify a single user directly, bypassing permission checks.
     * Used for "your request was approved" style messages.
     */
    public function notifyUser(?User $user, string $title, string $message, string $type = 'info', ?string $url = null): void
    {
        if (! $user) {
            return;
        }

        try {
            $user->notify(new SystemNotification($title, $message, $type, $url));
        } catch (\Throwable $e) {
            $this->logFailure($user->email ?? (string) $user->id, $title, $e);
        }
    }

    /**
     * A notification is never important enough to fail the business action
     * that triggered it, so every dispatch is guarded.
     */
    private function logFailure(string $target, string $title, \Throwable $e): void
    {
        Log::warning('Notification dispatch failed', [
            'target' => $target,
            'title' => $title,
            'error' => $e->getMessage(),
        ]);
    }

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
