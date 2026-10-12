<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $sale->receipt_number }} - {{ company_name() }}</title>

    {{--
        A point-of-sale receipt, on either an 80mm thermal roll or A4.

        POS receipts had no printable form at all -- the only view was an HTML
        screen page, so a shop could not hand a customer a receipt or reprint one.
        Every other money document in the system has a PDF; this is the one that
        did not.

        Two paper sizes, because they are genuinely different jobs:

          80mm  the thermal roll in the till. Narrow, monospaced-friendly, no
                colour fills, nothing that wastes ribbon. Height is a full A4
                worth of roll so a sale with many lines has room; the printer
                cuts it to the real length.

          a4    the reprint / keep-a-copy version. Wider, so the line items get
                real columns instead of wrapping.

        DomPDF notes: no Blade layout, no components, no Tailwind, no CSS custom
        properties (it does not resolve them). Widths are percentages because
        DomPDF ignores millimetres on <td>. Colours are literal so the document is
        legible on a greyscale thermal head.
    --}}
    <style>
        * { box-sizing: border-box; }

        body {
            font-family: 'DejaVu Sans Mono', 'DejaVu Sans', Helvetica, monospace;
            color: #000000;
            margin: 0;
            padding: 0;
            font-size: {{ $narrow ? '9px' : '10px' }};
            line-height: 1.4;
        }

        .sheet { padding: {{ $narrow ? '4mm' : '14mm' }}; }

        /* Centred letterhead. Centred rather than left because on an 80mm roll
           there is no room for a two-column header, and a receipt is read by
           looking at it, not by scanning it. */
        .letterhead { text-align: center; }
        .brand-name { font-size: {{ $narrow ? '13px' : '20px' }}; font-weight: bold; letter-spacing: 0.02em; }
        .brand-meta { font-size: 8px; margin-top: 1px; }

        .doc-title {
            font-size: {{ $narrow ? '10px' : '15px' }};
            font-weight: bold;
            margin: 4px 0 0;
            letter-spacing: 0.08em;
        }

        .rule { border-top: 1px dashed #000000; margin: 5px 0; }
        .rule-solid { border-top: 1px solid #000000; margin: 5px 0; }

        table { width: 100%; border-collapse: collapse; }
        table.meta td { font-size: 8px; padding: 0.5px 0; vertical-align: top; }
        table.meta td.label { width: 34%; color: #333333; }
        table.meta td.value { width: 66%; font-weight: bold; text-align: right; }

        /* Line items. On the roll each line is two rows -- the description and the
           money -- because three columns in 72mm of printable width wrap every
           description into an unreadable block. A4 gets the columns back. */
        table.items { margin-top: 3px; }
        table.items th {
            font-size: 8px;
            text-align: left;
            border-bottom: 1px solid #000000;
            padding: 2px 0;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        table.items td { padding: 1.5px 0; vertical-align: top; font-size: 8.5px; }
        table.items td.num { text-align: right; white-space: nowrap; }
        table.items tr { page-break-inside: avoid; }

        table.totals { margin-top: 4px; }
        table.totals td { padding: 1px 0; font-size: 8.5px; }
        table.totals td.label { text-align: left; }
        table.totals td.value { text-align: right; font-weight: bold; white-space: nowrap; }
        table.totals tr.grand td {
            border-top: 1px solid #000000;
            border-bottom: 1px solid #000000;
            font-size: {{ $narrow ? '11px' : '13px' }};
            padding: 3px 0;
        }

        .paid { margin-top: 4px; }
        .paid td { font-size: 8.5px; padding: 1px 0; }
        .paid td.label { text-align: left; }
        .paid td.value { text-align: right; font-weight: bold; }

        .footer { margin-top: 7px; text-align: center; font-size: 8px; }
        .footer .thanks { font-weight: bold; letter-spacing: 0.06em; }

        /* Cash-due box. Bordered rather than shaded: a thermal head renders fills
           as grey mud, and the figure is the one number a customer checks. */
        .change {
            margin-top: 5px;
            border: 1px solid #000000;
            padding: 4px 6px;
            text-align: center;
        }
        .change .label { font-size: 8px; letter-spacing: 0.08em; }
        .change .value { font-size: {{ $narrow ? '15px' : '18px' }}; font-weight: bold; }

        /* Punched through by the till. */
        .feed { margin-top: 8px; height: 8px; overflow: hidden; }
        .feed .zig {
            font-size: 9px;
            letter-spacing: 2px;
            white-space: nowrap;
            word-spacing: 6px;
            text-align: center;
            color: #000000;
        }
    </style>
</head>
<body>
    <div class="sheet">
        <div class="letterhead">
            <div class="brand-name">{{ company_name() }}</div>
            @if (filled(settings('company.address')))
                <div class="brand-meta">{!! nl2br(e(settings('company.address'))) !!}</div>
            @endif
            @if (filled(settings('company.phone')))
                <div class="brand-meta">Tel: {{ settings('company.phone') }}</div>
            @endif
            @if (filled(settings('company.tax_number')))
                <div class="brand-meta">Tax No: {{ settings('company.tax_number') }}</div>
            @endif
        </div>

        <div class="doc-title">SALES RECEIPT</div>

        <div class="rule"></div>

        <table class="meta">
            <tr>
                <td class="label">Receipt</td>
                <td class="value">{{ $sale->receipt_number }}</td>
            </tr>
            <tr>
                <td class="label">Date</td>
                <td class="value">{{ $sale->sold_at?->format('d/m/Y H:i') ?: '-' }}</td>
            </tr>
            <tr>
                <td class="label">Served by</td>
                <td class="value">{{ $sale->shift?->opener?->name ?: 'Cashier' }}</td>
            </tr>
            <tr>
                <td class="label">Customer</td>
                <td class="value">{{ $sale->customer_name ?: 'Walk-in' }}</td>
            </tr>
            <tr>
                <td class="label">Payment</td>
                <td class="value">{{ $sale->paymentMethod?->name ?: 'Cash' }}</td>
            </tr>
        </table>

        <div class="rule"></div>

        <table class="items">
            <thead>
                <tr>
                    <th style="width: {{ $narrow ? '58%' : '52%' }};">Item</th>
                    <th class="num" style="width: {{ $narrow ? '14%' : '12%' }};">Qty</th>
                    <th class="num" style="width: {{ $narrow ? '28%' : '18%' }};">Amount</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($sale->items as $item)
                    <tr>
                        <td>
                            {{ $item->item_name }}
                            @if ($narrow)
                                {{-- unit_price is dropped on the roll: with 58% of 72mm
                                     for the description there is no room for a third
                                     figure, and the line total already carries it. --}}
                            @else
                                <br>
                                <span style="font-size: 7.5px;">
                                    @ {{ number_format((float) $item->unit_price, 2) }}{{ $item->unit ? ' / '.$item->unit : '' }}
                                </span>
                            @endif
                        </td>
                        <td class="num">{{ rtrim(rtrim(number_format((float) $item->quantity, 3), '0'), '.') }}</td>
                        <td class="num">{{ number_format((float) $item->line_total, 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3">No items</td></tr>
                @endforelse
            </tbody>
        </table>

        <div class="rule"></div>

        <table class="totals">
            <tr>
                <td class="label">Subtotal</td>
                <td class="value">{{ number_format((float) $sale->subtotal, 2) }}</td>
            </tr>
            @if ((float) $sale->discount > 0)
                <tr>
                    <td class="label">Discount</td>
                    <td class="value">-{{ number_format((float) $sale->discount, 2) }}</td>
                </tr>
            @endif
            @if ((float) $sale->tax > 0)
                <tr>
                    <td class="label">Tax</td>
                    <td class="value">{{ number_format((float) $sale->tax, 2) }}</td>
                </tr>
            @endif
            <tr class="grand">
                <td class="label">TOTAL {{ $sale->currency ?? settings('company.currency', 'USD') }}</td>
                <td class="value">{{ number_format((float) $sale->total, 2) }}</td>
            </tr>
        </table>

        <table class="paid">
            <tr>
                <td class="label">Amount paid</td>
                <td class="value">{{ number_format((float) $sale->amount_paid, 2) }}</td>
            </tr>
        </table>

        @if ((float) $sale->change_due > 0)
            <div class="change">
                <div class="label">CHANGE DUE</div>
                <div class="value">{{ number_format((float) $sale->change_due, 2) }}</div>
            </div>
        @endif

        <div class="rule"></div>

        <div class="footer">
            @if (filled($sale->notes))
                <div>{!! nl2br(e($sale->notes)) !!}</div>
                <div class="rule"></div>
            @endif
            <div class="thanks">THANK YOU</div>
            <div>Please keep this receipt for returns</div>
            @if (filled(settings('company.website')))
                <div>{{ settings('company.website') }}</div>
            @endif
            <div style="margin-top: 2px;">{{ now()->format('d/m/Y H:i') }}</div>
        </div>

        {{-- Feed cut. Dashes and spaces only, so it prints on any thermal head. --}}
        <div class="feed">
            <div class="zig">- - - - - - - - - - - - - - - - - - - - - -</div>
        </div>
    </div>
</body>
</html>
