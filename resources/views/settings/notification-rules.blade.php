<x-settings-layout page-title="Notification Rules">
    <x-page-header title="Notification Rules" description="Define which event, channels and content are used to notify customers and staff." icon="bell" />

    <div class="mt-6 space-y-6">
        <x-alert type="info" class="mb-1">
            Rules are evaluated per event. The first matching enabled rule determines which channels are used. Channels that are disabled (via Company Profile &gt; Notifications) or that a recipient has no contact for are skipped automatically.
        </x-alert>

        @foreach ($rules as $rule)
            <x-card :title="$events[$rule->event] ?? $rule->event" class="overflow-hidden">
                <x-slot name="actions">
                    <form method="POST" action="{{ route('settings.notification-rules.toggle', $rule) }}" class="flex items-center gap-2">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="enabled" value="{{ $rule->enabled ? '0' : '1' }}">
                        <button
                            type="submit"
                            class="relative inline-flex h-6 w-11 cursor-pointer items-center rounded-full transition-colors duration-200 {{ $rule->enabled ? 'bg-primary' : 'bg-ink-faint/40' }}"
                            title="{{ $rule->enabled ? 'Enabled — click to disable' : 'Disabled — click to enable' }}"
                        >
                            <span class="inline-block size-4 transform rounded-full bg-white shadow transition-transform duration-200 {{ $rule->enabled ? 'translate-x-6' : 'translate-x-1' }}"></span>
                        </button>
                    </form>
                    <form method="POST" action="{{ route('settings.notification-rules.destroy', $rule) }}" onsubmit="return confirm('Delete this notification rule?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn-ghost btn-sm text-rose-500"><x-icon name="trash" class="size-4" /></button>
                    </form>
                </x-slot>
                <form method="POST" action="{{ route('settings.notification-rules.update', $rule) }}" class="space-y-5">
                    @csrf
                    @method('PUT')

                    <div class="max-w-md">
                        <x-input name="label" label="Label" value="{{ old('label', $rule->label) }}" required />
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div class="space-y-2">
                            <span class="label">Channels</span>
                            @foreach (['mail', 'sms', 'whatsapp', 'database'] as $channel)
                                <label class="flex items-center gap-2 text-sm text-ink">
                                    <input
                                        type="checkbox"
                                        name="channels[]"
                                        value="{{ $channel }}"
                                        {{ in_array($channel, $rule->channels ?? [], true) ? 'checked' : '' }}
                                        class="size-4 rounded border-line text-primary focus:ring-primary"
                                    >
                                    {{ ucfirst($channel) }}
                                </label>
                            @endforeach
                            @error('channels')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                        </div>

                        <div class="space-y-4">
                            <x-input name="subject" label="Subject" value="{{ old('subject', $rule->subject) }}" hint="Optional override for email subject / push title." />
                            <div>
                                <label class="label" for="message-{{ $rule->id }}">Message</label>
                                <textarea
                                    name="message"
                                    id="message-{{ $rule->id }}"
                                    rows="3"
                                    class="input w-full"
                                    placeholder="Optional body; defaults to a generated message."
                                >{{ old('message', $rule->message) }}</textarea>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end border-t border-line pt-4">
                        <x-button type="submit" icon="save">Save rule</x-button>
                    </div>
                </form>
            </x-card>
        @endforeach

        {{-- Create new rule --}}
        <x-card title="Add a rule" description="Attach channels to an event that does not have a rule yet.">
            <form method="POST" action="{{ route('settings.notification-rules.store') }}" class="space-y-5">
                @csrf

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-select name="event" label="Event" required>
                        @foreach ($events as $key => $label)
                            <option value="{{ $key }}" {{ old('event') === $key ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </x-select>
                    <x-input name="label" label="Label" value="{{ old('label') }}" required />
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="space-y-2">
                        <span class="label">Channels</span>
                        @foreach (['mail', 'database', 'sms', 'whatsapp'] as $channel)
                            <label class="flex items-center gap-2 text-sm text-ink">
                                <input
                                    type="checkbox"
                                    name="channels[]"
                                    value="{{ $channel }}"
                                    {{ in_array($channel, old('channels', ['mail']), true) ? 'checked' : '' }}
                                    class="size-4 rounded border-line text-primary focus:ring-primary"
                                >
                                {{ ucfirst($channel) }}
                            </label>
                        @endforeach
                        @error('channels')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                    </div>
                </div>

                <div class="flex justify-end border-t border-line pt-4">
                    <x-button type="submit" icon="plus">Create rule</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-settings-layout>