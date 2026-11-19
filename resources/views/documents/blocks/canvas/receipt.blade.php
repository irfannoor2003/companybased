{{--
    The receipt's single amount, in place of an invoice's line items and totals.

    A money-received / money-paid document has nothing to add up: it has one
    figure and the details that identify what it settled. Printing an empty item
    table above "Subtotal 0.00" is how a receipt reads as a broken invoice, so
    `DocumentData` supplies this block instead and switches the item and total
    blocks off. For every other document `$receipt` is null and this is a no-op,
    which lets the block live in every template's order without affecting them.
--}}
@if (! empty($receipt))
    <div class="receipt-amount">
        <table class="receipt-amount-table">
            <tr>
                <td class="receipt-amount-label">{{ $receipt['amountLabel'] }}</td>
            </tr>
            <tr>
                <td class="receipt-amount-value">{{ money($receipt['amount'], $receipt['currency']) }}</td>
            </tr>
        </table>
    </div>
@endif
