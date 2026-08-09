@php
    $steps = ['confirmed', 'packed', 'shipped', 'delivered'];
    $labels = ['Confirmed', 'Packed', 'Shipped', 'Delivered'];
    $currentIndex = array_search($order->status, $steps, true);
    $statusColor = match ($order->status) {
        'cancelled' => 'danger',
        'delivered' => 'success',
        default => 'primary',
    };
@endphp

<x-guest-layout>
    <div class="text-center">
        <h1 class="text-lg font-bold text-ink">Track your order</h1>
        <p class="mt-1 text-sm text-ink-faint">Order {{ $order->number }}</p>

        <span class="mt-4 inline-flex items-center gap-1.5 rounded-full bg-primary/10 px-3 py-1 text-sm font-medium text-primary">
            <span class="size-2 rounded-full bg-primary"></span>
            {{ ucfirst($order->status) }}
        </span>
    </div>

    @if ($order->status === 'cancelled')
        <div class="mt-6 rounded-xl border border-danger/30 bg-danger/5 p-4 text-center text-sm text-danger">
            This order was cancelled.
        </div>
    @else
        {{-- Timeline --}}
        <div class="mt-7 space-y-0">
            @foreach ($steps as $i => $step)
                @php
                    $done = $step <= $currentIndex;
                @endphp
                <div class="relative flex gap-3">
                    @if ($i < count($steps) - 1)
                        <div class="absolute left-[11px] top-7 h-full w-px {{ $done && $steps[$i + 1] <= $currentIndex ? 'bg-primary' : 'bg-line' }}"></div>
                    @endif
                    <div class="flex size-6 shrink-0 items-center justify-center rounded-full {{ $done ? 'bg-primary text-white' : 'bg-surface-muted text-ink-faint' }}">
                        @if ($done)
                            <x-icon name="check-circle" class="size-4" />
                        @endif
                    </div>
                    <div class="pb-5">
                        <p class="text-sm font-semibold {{ $done ? 'text-ink' : 'text-ink-faint' }}">{{ $labels[$i] }}</p>
                        @php
                            $event = $order->statusEvents
                                ? $order->statusEvents->where('to_status', $steps[$i])->sortByDesc('created_at')->first()
                                : null;
                            $event = $order->statusEvents->firstWhere('to_status', $steps[$i]);
                        @endphp
                        @if ($event)
                            <p class="mt-0.5 text-xs text-ink-soft">{{ $event->created_at->format('M d, Y H:i') }}</p>
                            @if ($event->note)
                                <p class="mt-0.5 text-xs text-ink-faint">{{ $event->note }}</p>
                            @endif
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Item summary --}}
    @if ($order->items->isNotEmpty())
        <div class="mt-2 border-t border-line pt-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-ink-faint">Items</p>
            <ul class="mt-2 space-y-1.5">
                @foreach ($order->items as $item)
                    <li class="flex justify-between text-sm text-ink-soft">
                        <span class="line-clamp-2">{{ $item->qty }} × {{ $item->product?->name ?? $item->description }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Delivery info --}}
    <div class="mt-4 flex flex-col gap-3 border-t border-line pt-4 text-sm sm:flex-row sm:justify-between">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-ink-faint">Payment</p>
            <span class="mt-1 inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium
                {{ $paymentStatus === 'Paid' ? 'bg-success/10 text-success' : ($paymentStatus === 'Partial' ? 'bg-warning/10 text-warning' : 'bg-neutral/10 text-ink-soft') }}">
                {{ $paymentStatus }}
            </span>
        </div>
        @if ($order->expected_delivery_date)
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-ink-faint">Estimated delivery</p>
                <p class="mt-1 text-sm text-ink-soft">{{ $order->expected_delivery_date->format('M d, Y') }}</p>
            </div>
        @endif
        @if ($order->deliveryNotes->where('carrier')->first()?->carrier)
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-ink-faint">Carrier</p>
                <p class="mt-1 text-sm text-ink-soft">{{ $order->deliveryNotes->where('carrier')->first()->carrier }}</p>
            </div>
        @endif
    </div>

    <a href="{{ url('/') }}" class="mt-6 block text-center text-sm text-primary hover:underline">Back to {{ $appBrand['companyName'] }}</a>
</x-guest-layout>