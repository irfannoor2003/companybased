{{--
    What the tax engine expects on this document's date.

    Shared by the sales and purchase line-item editors so the two read identically
    -- a purchase form that showed different wording from a sales form would be a
    small sign of two applications rather than one.

    The Tax % input is a plain number with no knowledge of the rate catalogue, so
    without this the user has to know the current rate and type it on every
    document. When no rate is configured it says so explicitly rather than
    implying a zero rate: a missing rate and an exemption are different facts on a
    return, and only one of them belongs in the exempt box.
--}}
@if ($taxHint)
    <div class="mb-3 rounded-lg border px-3 py-2 text-xs
        {{ $taxHint['percent'] === null ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-line bg-surface-muted text-ink-soft' }}">
        @if ($taxHint['percent'] === null)
            {{ $taxHint['reason'] }}
        @else
            <span class="font-medium text-ink">{{ $taxHint['rate_name'] }}: {{ $taxHint['percent'] }}%</span>
            @if ($taxHint['reason'])
                <span class="ml-1">&mdash; {{ $taxHint['reason'] }}</span>
            @endif
        @endif
    </div>
@endif