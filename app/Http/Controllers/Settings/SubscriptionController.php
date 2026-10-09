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

        // `?? null` rather than `?: null`: both fields are nullable, so a request
        // that omits them (an API call, a partial form post, curl) leaves the key
        // absent from the validated array entirely and `$data['notes'] ?? null` raises
        // "Undefined array key". The shipped form always submits both inputs, which
        // is the only reason this had not surfaced.
        $subscription->plan_name = $data['plan_name'] ?? null;
        $subscription->starts_at = now();

        if (! empty($data['expires_at'] ?? null)) {
            $subscription->expires_at = $data['expires_at'] ?? null;
        } else {
            $subscription->expires_at = now()->addDays((int) ($data['duration_days'] ?? null));
        }

        $subscription->is_active = true;
        $subscription->reminder_sent_at = null;
        $subscription->notes = $data['notes'] ?? null;
        $subscription->save();

        // The access verdict is memoised per process; without this the newly
        // activated package would not be observed until the worker restarted, and
        // staff would stay blocked through the very screen that unblocked them.
        Subscription::flushCache();

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

        // Deactivating must lift the block immediately, for the same reason
        // activation has to be visible immediately.
        Subscription::flushCache();

        return redirect()
            ->route('settings.subscription')
            ->with('success', 'Package deactivated. Because no active package remains, staff access is now BLOCKED until a package is activated again.');
    }
}
