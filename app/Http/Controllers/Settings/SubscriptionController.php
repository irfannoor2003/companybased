<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SubscriptionController extends Controller
{
    public function index(): View
    {
        abort_if(! auth()->user()?->isSuperAdmin(), 403);

        $subscription = Subscription::current();

        return view('settings.subscription', compact('subscription'));
    }

    public function activate(Request $request): RedirectResponse
    {
        abort_if(! auth()->user()?->isSuperAdmin(), 403);

        $data = $request->validate([
            'plan_name' => ['nullable', 'string', 'max:120'],
            'duration_days' => ['required_without:expires_at', 'nullable', 'integer', 'min:1', 'max:3650'],
            'expires_at' => ['required_without:duration_days', 'nullable', 'date', 'after:now'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $subscription = Subscription::current() ?? new Subscription();

        $subscription->plan_name = $data['plan_name'] ?: null;
        $subscription->starts_at = now();

        if (! empty($data['expires_at'])) {
            $subscription->expires_at = $data['expires_at'];
        } else {
            $subscription->expires_at = now()->addDays((int) $data['duration_days']);
        }

        $subscription->is_active = true;
        $subscription->reminder_sent_at = null;
        $subscription->notes = $data['notes'] ?: null;
        $subscription->save();

        return redirect()
            ->route('settings.subscription')
            ->with('success', 'Package activated. Access will be blocked for staff once it expires.');
    }

    public function deactivate(): RedirectResponse
    {
        abort_if(! auth()->user()?->isSuperAdmin(), 403);

        $subscription = Subscription::current();

        if ($subscription) {
            $subscription->is_active = false;
            $subscription->save();
        }

        return redirect()
            ->route('settings.subscription')
            ->with('success', 'Package deactivated. All users now have unrestricted access.');
    }
}
