{{--
    The line items table.

    The one block that cannot be switched off, so it is the block every other one
    has to keep out of the way of. Column widths are percentages because DomPDF
    silently ignores millimetre widths on `<td>`, and `<th>` carries them rather
    than a `<colgroup>`, which DomPDF ignores outright.

    Every row is `page-break-inside: avoid`. That is what keeps a single line from
    splitting across a page boundary and printing its description on one page and
    its amount on the next.

    Figures come from `DocumentData::rowsFor()`, which prices every line through
    `DocumentItems::lineTotals()` -- the same arithmetic the create and edit
    screens use. Recomputing here in float is how the printed rows and the stored
    totals drifted apart by a cent.
--}}
<table class="items">
    <thead>
        <tr>
            <th class="idx" style="width: 7%;">#</th>
            <th style="width: 33%;">Description</th>
            @if ($hasPricing)
                <th class="num" style="width: 8%;">Qty</th>
                <th class="num" style="width: 13%;">Unit price</th>
                <th class="num" style="width: 11%;">Disc.</th>
                <th class="num" style="width: 11%;">Tax</th>
                <th class="num" style="width: 17%;">Total</th>
            @else
                <th class="num" style="width: 60%;">Qty</th>
            @endif
        </tr>
    </thead>
    <tbody>
        @foreach ($rows as $index => $row)
            <tr @if ($layout->style() === \App\Support\PdfLayout::STYLE_BANDED && $index % 2 === 1) class="alt" @endif>
                <td class="idx">{{ $index + 1 }}</td>
                <td class="desc">{{ $row['description'] ?? '-' }}</td>
                @if ($hasPricing)
                    <td class="num">{{ number_format((float) ($row['qty'] ?? 0), 2) }}</td>
                    <td class="num">{{ number_format((float) ($row['unit_price'] ?? 0), 2) }}</td>
                    <td class="num">{{ (float) ($row['discount'] ?? 0) > 0 ? number_format((float) $row['discount'], 2) : '-' }}</td>
                    <td class="num">{{ number_format((float) ($row['tax'] ?? 0), 2) }}</td>
                    <td class="num total">{{ number_format((float) ($row['total'] ?? 0), 2) }}</td>
                @else
                    <td class="num">{{ number_format((float) ($row['qty'] ?? 0), 2) }}</td>
                @endif
            </tr>
        @endforeach
    </tbody>
</table>
