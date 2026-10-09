{{--
    Close and lock are separate permissions on purpose. Closing stops new entries and
    is reversible while the figures are being checked; locking is final. Handing one
    role both would make the distinction meaningless.

    Reopen is only offered while the period is closed: a locked one cannot be
    reopened, so the button would only ever produce an error.
--}}
<div class="inline-flex flex-wrap items-center justify-end gap-2">
    @if (auth()->user()->can('accounting.fiscal_periods.edit') && ! $period->isLocked())
        <x-button href="{{ route('accounting.fiscal_periods.edit', $period) }}"
                  variant="ghost" size="sm">Edit</x-button>
    @endif

    @if (auth()->user()->can('accounting.fiscal_periods.close'))
        @if ($period->isOpen())
            <form method="POST" action="{{ route('accounting.fiscal_periods.close', $period) }}"
                  onsubmit="return confirm('Close {{ $period->label() }}? No further entries can be dated in it.');">
                @csrf
                <x-button type="submit" variant="secondary" size="sm">Close</x-button>
            </form>
        @elseif ($period->status === 'closed')
            <form method="POST" action="{{ route('accounting.fiscal_periods.reopen', $period) }}">
                @csrf
                <x-button type="submit" variant="ghost" size="sm">Reopen</x-button>
            </form>
        @endif
    @endif

    @if (auth()->user()->can('accounting.fiscal_periods.lock') && ! $period->isLocked())
        <form method="POST" action="{{ route('accounting.fiscal_periods.lock', $period) }}"
              onsubmit="return confirm('Lock {{ $period->label() }}? This is final and cannot be undone.');">
            @csrf
            <x-button type="submit" variant="danger" size="sm">Lock</x-button>
        </form>
    @endif
</div>
