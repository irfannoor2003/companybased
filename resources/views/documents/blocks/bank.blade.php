{{--
    Bank details, for paying an invoice.

    Rendered only when an account number is on file. A bank block with a heading and
    nothing under it is worse than no block at all.
--}}
@if (filled(settings('company.bank_account_number')))
    <div class="rail-label">Bank</div>
    @if (filled(settings('company.bank_name')))
        <div class="rail-line">{{ settings('company.bank_name') }}</div>
    @endif
    <div class="rail-line">A/C {{ settings('company.bank_account_number') }}</div>
@endif