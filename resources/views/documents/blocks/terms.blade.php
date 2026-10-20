{{-- Payment terms. Empty terms print no block rather than an empty heading. --}}
@if (filled(settings('company.payment_terms')))
    <div class="rail-label">Terms</div>
    <div class="rail-terms">{!! nl2br(e(settings('company.payment_terms'))) !!}</div>
@endif