<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function readAll(): RedirectResponse
    {
        auth()->user()->unreadNotifications->markAsRead();

        return back();
    }

    /**
     * Mark a single notification read and follow it to its target.
     *
     * The lookup is scoped to the signed-in user's own notifications, so a
     * guessed id can never mark — or reveal — somebody else's.
     */
    public function read(Request $request, string $notification): RedirectResponse
    {
        $target = auth()->user()->notifications()->findOrFail($notification);

        if ($target->read_at === null) {
            $target->markAsRead();
        }

        $url = data_get($target->data, 'url');

        return $url ? redirect()->to($url) : back();
    }
}
