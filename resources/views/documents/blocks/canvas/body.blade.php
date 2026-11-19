{{--
    A document with its own body shape (a payslip, for example) names its view in
    `$body`. This block is a no-op for every other document, which is why it can
    live in every template's default order without changing an invoice.
--}}
@if (! empty($body))
    @include($body)
@endif
