<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\NotificationRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

class NotificationRuleController extends Controller
{
    public function index(): View
    {
        $rules = NotificationRule::query()->orderBy('id')->get();
        $events = NotificationRule::availableEvents();

        return view('settings.notification-rules', compact('rules', 'events'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'event' => ['required', 'string', 'in:' . implode(',', array_keys(NotificationRule::availableEvents())), 'unique:notification_rules,event'],
            'label' => ['required', 'string', 'max:120'],
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => ['required', 'string', 'in:mail,sms,whatsapp,database'],
            'enabled' => ['boolean'],
            'subject' => ['nullable', 'string', 'max:200'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        NotificationRule::create([
            'event' => $data['event'],
            'label' => $data['label'],
            'channels' => $data['channels'],
            'enabled' => $request->has('enabled') ? $request->boolean('enabled') : true,
            'subject' => $data['subject'] ?? null,
            'message' => $data['message'] ?? null,
        ]);

        return back()->with('toasts', [['type' => 'success', 'message' => 'Notification rule created.']]);
    }

    public function update(Request $request, NotificationRule $rule): RedirectResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:120'],
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => ['required', 'string', 'in:mail,sms,whatsapp,database'],
            'enabled' => ['boolean'],
            'subject' => ['nullable', 'string', 'max:200'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        $rule->update([
            'label' => $data['label'],
            'channels' => $data['channels'],
            'enabled' => $request->boolean('enabled'),
            'subject' => $data['subject'] ?? null,
            'message' => $data['message'] ?? null,
        ]);

        return back()->with('toasts', [['type' => 'success', 'message' => 'Notification rule updated.']]);
    }

    public function toggle(Request $request, NotificationRule $rule): RedirectResponse
    {
        $validated = Validator::make($request->all(), [
            'enabled' => ['required', 'boolean'],
        ])->validated();

        $rule->update(['enabled' => (bool) $validated['enabled']]);

        return back()->with('toasts', [['type' => 'success', 'message' => $rule->enabled ? 'Rule enabled.' : 'Rule disabled.']]);
    }

    public function destroy(NotificationRule $rule): RedirectResponse
    {
        $rule->delete();

        return back()->with('toasts', [['type' => 'success', 'message' => 'Notification rule deleted.']]);
    }
}
