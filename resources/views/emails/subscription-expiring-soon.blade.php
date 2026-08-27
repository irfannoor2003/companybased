<x-mail::message>
# Package Expiring Soon

The **{{ $subscription->plan_name ?: 'current' }}** package for this installation will expire in **{{ $daysRemaining }} day{{ $daysRemaining === 1 ? '' : 's' }}** (on {{ $subscription->expires_at?->format('d M Y') }}).

Once the package expires, all staff (except the Super Admin) will lose access to the system until the package is renewed.

Please renew the package from the **Settings → Packages** panel to avoid interruption.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
