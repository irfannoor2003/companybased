{{--
    The settlement block of a sales invoice: what was billed, what was received,
    and what is still owed.

    Shared by the classic PDF template and the skeleton behind modern / minimal /
    corporate, because a customer who is told two different things about the same
    invoice depending on which template the office picked is worse than either
    answer.

    DomPDF renders these directly: no layout, no Blade components, no Tailwind.
    Only the plain-CSS class names passed in may be used, because each template
    themes them differently. Every multi-column band must be a real <table> -
    DomPDF has no CSS table box model and throws
    "Min/max width is undefined for table rows" on any table-cell div.
--}}
@php
    $settledCredit = (float) ($invoice->credit_applied ?? 0);

    /*
     * Gross before the line discounts, so the Discount row can be shown.
     *
     * `subtotal` is already net of the line-level discounts, so printing it and
     * labelling it "Subtotal" made the PDF's totals disagree with the invoice
     * screen - 4,015 on the document against 4,405 less a 390 discount on
     * screen - with no discount line anywhere to explain the difference. The
     * total was right either way; the customer simply could not see why.
     */
    $gross = (float) $invoice->items->sum(
        fn ($item): float => round((float) $item->qty * (float) $item->unit_price, 2)
    );

    /*
     * Fall back to the stored subtotal when there are no lines to sum.
     *
     * An invoice with no items - a document created from an order whose lines
     * were never attached, or a header-only draft - summed to a gross of 0.00 and
     * printed "Subtotal 0.00" directly above a non-zero "Total", which reads as a
     * broken document rather than as an empty one.
     */
    $gross = $gross > 0.0 ? $gross : (float) $invoice->subtotal;

    $discount = round($gross - (float) $invoice->subtotal, 2);

    $classes = $classes ?? [];
    $labelClass = $classes['label'] ?? '';
    $valueClass = $classes['value'] ?? '';
    $settledClass = $classes['settled'] ?? '';
    $dueClass = $invoice->balance() > 0 ? ($classes['due'] ?? '') : ($classes['paid'] ?? '');
@endphp

<table class="totals">
    <tr>
        <td class="{{ $labelClass }}">Subtotal</td>
        <td class="{{ $valueClass }}">{{ number_format($gross, 2) }}</td>
    </tr>
    @if ($discount > 0)
        <tr>
            <td class="{{ $labelClass }}">Discount</td>
            <td class="{{ $valueClass }}">-{{ number_format($discount, 2) }}</td>
        </tr>
    @endif
    <tr>
        <td class="{{ $labelClass }}">Tax</td>
        <td class="{{ $valueClass }}">{{ number_format((float) $invoice->tax_amount, 2) }}</td>
    </tr>
    <tr class="grand">
        <td class="{{ $labelClass }}">Total</td>
        <td class="{{ $valueClass }}">{{ number_format((float) $invoice->total, 2) }}</td>
    </tr>
    <tr>
        <td class="{{ $labelClass }}">Paid</td>
        <td class="{{ $valueClass }}">{{ number_format((float) $invoice->paid_amount, 2) }}</td>
    </tr>
    @if ($settledCredit > 0)
        {{-- Named explicitly rather than merged into "Paid": the customer needs
             to see that this document was settled with money they had already
             handed over on an earlier one. --}}
        <tr>
            <td class="{{ $labelClass }}">
                Credit from customer balance
                <span class="totals-note">received on an earlier invoice</span>
            </td>
            <td class="{{ $settledClass }}">-{{ number_format($settledCredit, 2) }}</td>
        </tr>
    @endif
    <tr class="{{ trim('due '.$dueClass) }}">
        <td class="{{ $labelClass }}">{{ $settledCredit > 0 ? 'Remaining balance' : 'Balance due' }}</td>
        <td class="{{ $valueClass }}">{{ number_format($invoice->balance(), 2) }}</td>
    </tr>
</table>