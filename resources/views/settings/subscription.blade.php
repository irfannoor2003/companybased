<x-settings-layout page-title="Packages">
    <x-page-header title="Packages" description="Control how long this installation stays active. Set an activation period and the system will lock out all staff (except the Super Admin) once it expires." icon="package">
        <x-slot name="actions">
            @if ($subscription && $subscription->is_active)
                <form method="POST" action="{{ route('settings.subscription.deactivate') }}"
                    onsubmit="return confirm('Deactivate the package? All users will regain access immediately.');">
                    @csrf
                    @method('DELETE')
                    <x-button type="submit" variant="secondary" icon="x">Deactivate</x-button>
                </form>
            @endif
        </x-slot>
    </x-page-header>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <x-card title="Current package" description="Status of the active subscription controlling access.">
            @if ($subscription && $subscription->is_active)
                <dl class="divide-y divide-line">
                    <div class="flex items-center justify-between py-2.5">
                        <dt class="text-sm text-ink-soft">Plan</dt>
                        <dd class="text-sm font-medium text-ink">{{ $subscription->plan_name ?: '—' }}</dd>
                    </div>
                    <div class="flex items-center justify-between py-2.5">
                        <dt class="text-sm text-ink-soft">Activated</dt>
                        <dd class="text-sm font-medium text-ink">{{ $subscription->starts_at?->format('d M Y, h:i A') ?: '—' }}</dd>
                    </div>
                    <div class="flex items-center justify-between py-2.5">
                        <dt class="text-sm text-ink-soft">Expires</dt>
                        <dd class="text-sm font-medium text-ink">{{ $subscription->expires_at?->format('d M Y, h:i A') ?: '—' }}</dd>
                    </div>
                    <div class="flex items-center justify-between py-2.5">
                        <dt class="text-sm text-ink-soft">Time remaining</dt>
                        <dd class="text-sm font-medium text-ink">
                            @php
                                $days = \App\Models\Subscription::daysRemaining();
                            @endphp
                            @if ($days !== null)
                                @if ($days > 0)
                                    <span class="text-emerald-600">{{ $days }} day{{ $days === 1 ? '' : 's' }}</span>
                                @else
                                    <span class="text-rose-600">Expired</span>
                                @endif
                            @else
                                —
                            @endif
                        </dd>
                    </div>
                </dl>
                <div class="mt-4">
                    <span class="badge badge-success">Active</span>
                </div>
            @else
                <x-empty-state icon="package" title="No active package"
                    description="There is no active subscription, so all users currently have unrestricted access. Activate a package below to start enforcing an expiry date." />
            @endif
        </x-card>

        @if (auth()->user()->isSuperAdmin())
            <x-card title="Activate / renew package"
                description="Add an activation period. Use the duration to extend from today, or set an explicit expiry date.">
                <form method="POST" action="{{ route('settings.subscription.activate') }}" class="space-y-4">
                    @csrf

                    <x-input type="text" name="plan_name" label="Plan name"
                        placeholder="e.g. Monthly, Quarterly, Annual"
                        value="{{ old('plan_name', $subscription->plan_name ?? '') }}" />

                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-input type="number" name="duration_days" label="Duration (days)"
                            min="1" max="3650" step="1" placeholder="30"
                            hint="Used when no explicit date is given." value="{{ old('duration_days') }}" />
                        <x-input type="date" name="expires_at" label="Or explicit expiry date"
                            min="{{ now()->format('Y-m-d') }}" value="{{ old('expires_at') }}" />
                    </div>

                    <x-input type="text" name="notes" label="Notes (optional)"
                        placeholder="Invoice #, payment reference…" value="{{ old('notes') }}" />

                    <div class="flex justify-end">
                        <x-button type="submit" icon="check">
                            {{ $subscription && $subscription->is_active ? 'Renew package' : 'Activate package' }}
                        </x-button>
                    </div>
                </form>
            </x-card>
        @else
            <x-card title="Restricted">
                <p class="text-sm text-ink-soft">Only the Super Admin can manage packages.</p>
            </x-card>
        @endif
    </div>
</x-settings-layout>
