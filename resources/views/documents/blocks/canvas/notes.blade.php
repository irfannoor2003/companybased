{{-- Free text the operator attached to the document. Skipped when there is none. --}}
@if (filled($notes))
    <div class="notes">
        <div class="rail-label">Notes</div>
        <p>{!! nl2br(e($notes)) !!}</p>
    </div>
@endif
