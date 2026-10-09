{{--
    Status comes from config/statuses.php via x-accounting.status-badge, so it stays
    consistent with every other accounting status. The hint under it carries the one
    fact that is not derivable from the colour: whether this state is reversible.
--}}
@if ($period->isLocked())
    <x-accounting.status-badge :status="$period->status" />
    <p class="mt-1 text-xs text-ink-faint">Final. Corrections need a reversing entry.</p>
@elseif ($period->status === 'closed')
    <x-accounting.status-badge :status="$period->status" />
    <p class="mt-1 text-xs text-ink-faint">Can be reopened while figures are checked.</p>
@else
    <x-accounting.status-badge :status="$period->status" />
@endif
