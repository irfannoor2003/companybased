<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->number }} &mdash; {{ company_name() }}</title>

    {{--
        A standalone document for DomPDF.

        Design: white space, one accent, and a type hierarchy that can be
        navigated at a glance. No dark rail down the side of the page, no
        abbreviated labels -- a customer-facing document should not be terse.

        DomPDF constraints, all load-bearing and all learned from blank pages:

          - No flexbox, no grid, no CSS custom properties, no calc(). Nested
            tables are the only layout primitive that survives.
          - Column widths MUST be percentages. `width: 60mm` is ignored even
            inline, and the sheet silently splits 50/50 and runs off the paper.
          - table-layout: fixed is mandatory. With `auto` the engine treats
            widths as hints and widens whatever it must, pushing the right-hand
            columns off the edge.
          - `@page { margin: 0 }` gives no rounding headroom, so widths total
            ~97%, not 100%.
          - A <thead> row is only as tall as its own content, so it cannot be
            used for anything that must reach the foot of the page. That is why
            the old full-height rail printed as a short strip. Nothing here
            needs to do that; the masthead is a one-off and the items <thead>
            is the part that legitimately repeats.

        Layout notes from the rendered result, because getting this wrong is
        easy and obvious once seen:

          - The issuer block and the document title sit on one row, both top
            aligned, and the rule runs the full content width beneath them.
            Splitting the title onto its own full-width row left an awkward
            gap and pushed the accent rule too far down the page.
          - The grand total is the ONLY emphasised figure. Totals are set as a
            compact right-aligned block at 44% width so they read as a unit
            rather than sprawling across the page.
    --}}
    <style>
        @php
            // Derived from the tenant's brand colour, so a Settings > Branding
            // change yields a coherent document rather than a stray indigo.
            $brand      = settings('branding.primary_color', '#4f46e5');
            $accent     = $brand;
            $accentWash = mix_hex($brand, '#ffffff', 95);

            $ink   = '#14161a';   // near-black, softer than pure #000
            $body  = '#3f444e';
            $muted = '#878d99';
            $rule  = '#e9ebef';
        @endphp

        * { box-sizing: border-box; }

        body {
            font-family: 'DejaVu Sans', Helvetica, Arial, sans-serif;
            font-size: 9px;
            line-height: 1.5;
            color: {{ $body }};
            margin: 0;
            padding: 0;
        }

        @page { margin: 0; }

        table.sheet {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            margin: 0;
            padding: 0;
        }
        table.sheet td { border: none; padding: 0; vertical-align: top; }

        /*
          The page margin.

          This has to be set inline on the cell. Two attempts at it via the
          stylesheet both failed and produced a document flush to the paper edge:

            1. `.pad { padding: 15mm 16mm ... }` lost to `table.sheet td
               { padding: 0 }` on specificity -- the descendant selector wins.
            2. `table.sheet > tbody > tr > td { padding: 0 }` to remove the
               conflict, plus `.page-pad` for the real padding. DomPDF does not
               apply child combinators the way a browser does, so the reset never
               stopped winning.

          Inline styles are the reliable path for this one property, and the
          rendered invoice is the only proof that works. */
        .page-pad { padding: 15mm 16mm 9mm 16mm; }

        /* ---- Masthead ------------------------------------------------------ */
        table.masthead {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        table.masthead td { border: none; padding: 0; vertical-align: top; }

        .issuer-logo { margin-bottom: 2px; }

        .issuer-name {
            font-size: 14px;
            font-weight: bold;
            letter-spacing: -0.3px;
            color: {{ $ink }};
            line-height: 1.2;
        }
        .issuer-meta {
            margin-top: 4px;
            font-size: 7.5px;
            color: {{ $muted }};
            line-height: 1.65;
        }

        /* The document type is small and quiet; the number is what identifies
           the document, so it carries the emphasis. */
        .doc-label {
            font-size: 7.5px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: {{ $muted }};
            text-align: right;
        }
        .doc-number {
            font-size: 17px;
            font-weight: bold;
            letter-spacing: -0.4px;
            color: {{ $ink }};
            line-height: 1.15;
            text-align: right;
            margin-top: 1px;
        }
        .status {
            display: inline-block;
            margin-top: 5px;
            padding: 2px 7px;
            font-size: 7px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.7px;
            color: #ffffff;
            background-color: {{ $accent }};
        }
        .status--paid  { background-color: #0f8a5f; }
        .status--void  { background-color: #b4322c; }
        .status--draft { background-color: {{ $muted }}; }

        /* A single hairline does the work the colour block used to do, without
           spending a quarter of the page to say nothing. */
        .accent-rule {
            height: 2px;
            background-color: {{ $accent }};
            margin: 9mm 0 8mm 0;
        }

        /* ---- Parties & dates ----------------------------------------------- */
        table.parties {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            margin-bottom: 7mm;
        }
        table.parties td { border: none; padding: 0 8mm 0 0; vertical-align: top; }

        .label {
            font-size: 7px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.9px;
            color: {{ $muted }};
            margin-bottom: 3px;
        }
        .party-name {
            font-size: 10px;
            font-weight: bold;
            color: {{ $ink }};
            line-height: 1.3;
            margin-bottom: 1px;
        }
        .party-line {
            font-size: 8px;
            color: {{ $body }};
            line-height: 1.5;
        }

        table.facts { width: 100%; border-collapse: collapse; }
        table.facts td {
            border: none;
            padding: 1px 0;
            font-size: 8px;
            vertical-align: top;
        }
        table.facts td.k {
            width: 50%;
            text-align: right;
            padding-right: 3mm;
            color: {{ $muted }};
        }
        table.facts td.v {
            width: 50%;
            text-align: right;
            font-weight: bold;
            color: {{ $ink }};
        }

        /* ---- Items --------------------------------------------------------- */
        table.items {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            margin-bottom: 5mm;
        }
        table.items th {
            font-size: 7px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.9px;
            color: {{ $muted }};
            text-align: left;
            padding: 0 3mm 2mm 0;
            border-bottom: 1px solid {{ $rule }};
        }
        table.items td {
            font-size: 8.5px;
            padding: 2.5mm 3mm 2.5mm 0;
            border-bottom: 1px solid {{ $rule }};
            vertical-align: top;
        }
        table.items th.num, table.items td.num {
            text-align: right;
            padding-right: 0;
        }
        table.items tr { page-break-inside: avoid; }

        .item-name { font-weight: bold; color: {{ $ink }}; }
        .item-sub {
            font-size: 7px;
            color: {{ $muted }};
            margin-top: 1px;
        }

        /* ---- Totals -------------------------------------------------------- */
        /* A compact block pushed right with spacer cells. DomPDF has no float, so
           right-alignment has to come from the table structure itself.

           Only the grand total is emphasised. Bolding every line makes the eye
           stop at the wrong number. */
        table.totals {
            width: 46%;
            border-collapse: collapse;
            margin-left: 54%;
            margin-bottom: 5mm;
        }
        table.totals td {
            border: none;
            padding: 1.2mm 0;
            font-size: 8.5px;
            vertical-align: top;
        }
        table.totals td.k {
            width: 56%;
            text-align: right;
            padding-right: 3mm;
            color: {{ $body }};
        }
        table.totals td.v {
            width: 44%;
            text-align: right;
            color: {{ $ink }};
        }
        table.totals tr.grand td {
            border-top: 1px solid {{ $ink }};
            padding-top: 2mm;
            font-size: 11px;
            font-weight: bold;
            color: {{ $ink }};
        }
        table.totals tr.due td {
            font-size: 10px;
            font-weight: bold;
            color: {{ $accent }};
        }
        /* "Paid" in full and in green, because a settled invoice reads
           differently from one awaiting money. */
        table.totals tr.settled td { font-size: 10px; font-weight: bold; color: #0f8a5f; }

        /* ---- Notes --------------------------------------------------------- */
        .notes {
            background-color: {{ $accentWash }};
            padding: 3.5mm 4mm;
            margin-bottom: 5mm;
            page-break-inside: avoid;
        }
        .notes p {
            margin: 0;
            font-size: 8px;
            color: {{ $body }};
            line-height: 1.55;
        }

        /* ---- Footer -------------------------------------------------------- */
        /* Faint and last. A footer that competes with the totals is over-designed. */
        .footer {
            border-top: 1px solid {{ $rule }};
            padding-top: 2.5mm;
            font-size: 7px;
            color: {{ $muted }};
            text-align: center;
        }
    </style>
</head>
<body>
    <table class="sheet">
        <tbody>
        <tr>
            {{-- Padding is inline: see the note on .page-pad in the stylesheet. --}}
            <td class="page-pad" style="padding: 15mm 16mm 9mm 16mm;">

                {{-- Masthead ---------------------------------------------------- --}}
                <table class="masthead">
                    <tr>
                        <td style="width: 56%;">
                            @if (settings('branding.logo'))
                                <div class="issuer-logo">
                                    <img src="{{ storage_path('app/public/'.settings('branding.logo')) }}"
                                         alt="{{ company_name() }}" style="height: 11mm;">
                                </div>
                            @endif
                            <div class="issuer-name">{{ company_name() }}</div>
                            <div class="issuer-meta">
                                @if (settings('company.address')){!! nl2br(e(settings('company.address'))) !!}@endif
                                @if (settings('company.phone'))<br>Tel {{ settings('company.phone') }}@endif
                                @if (settings('company.email'))<br>{{ settings('company.email') }}@endif
                                @if (settings('company.tax_number'))<br>Tax no. {{ settings('company.tax_number') }}@endif
                            </div>
                        </td>
                        <td style="width: 44%;">
                            <div class="doc-label">{{ $docLabel ?? 'Invoice' }}</div>
                            <div class="doc-number">{{ $invoice->number }}</div>
                            @php
                                $statusClass = 'status';
                                if (in_array($invoice->status, ['paid', 'partially_paid'], true)) {
                                    $statusClass .= ' status--paid';
                                } elseif (in_array($invoice->status, ['void', 'cancelled'], true)) {
                                    $statusClass .= ' status--void';
                                } elseif ($invoice->status === 'draft') {
                                    $statusClass .= ' status--draft';
                                }
                            @endphp
                            <div style="text-align: right;">
                                <span class="{{ $statusClass }}">{{ ucfirst(str_replace('_', ' ', $invoice->status)) }}</span>
                            </div>
                        </td>
                    </tr>
                </table>

                <div class="accent-rule"></div>

                {{-- Parties and dates -------------------------------------------- --}}
                <table class="parties">
                    <tr>
                        <td style="width: 50%;">
                            <div class="label">{{ $customerLabel ?? 'Bill to' }}</div>
                            <div class="party-name">{{ $invoice->customer?->company_name ?: '—' }}</div>
                            @if ($invoice->customer?->contact_name)
                                <div class="party-line">Attn. {{ $invoice->customer->contact_name }}</div>
                            @endif
                            @if ($invoice->customer?->address)
                                <div class="party-line">{!! nl2br(e($invoice->customer->address)) !!}</div>
                            @endif
                            @if ($invoice->customer?->city || $invoice->customer?->country)
                                <div class="party-line">{{ collect([$invoice->customer->city, $invoice->customer->country])->filter()->implode(', ') }}</div>
                            @endif
                            @if ($invoice->customer?->tax_number)
                                <div class="party-line">Tax no. {{ $invoice->customer->tax_number }}</div>
                            @endif
                            @if ($invoice->customer?->phone)
                                <div class="party-line">Phone {{ $invoice->customer->phone }}</div>
                            @endif
                            @if ($invoice->customer?->email)
                                <div class="party-line">{{ $invoice->customer->email }}</div>
                            @endif
                        </td>
                        <td style="width: 50%;">
                            <table class="facts">
                                <tr><td class="k">Issue date</td><td class="v">{{ $invoice->issue_date?->format('j M Y') }}</td></tr>
                                @if ($invoice->due_date)
                                    <tr><td class="k">Due date</td><td class="v">{{ $invoice->due_date->format('j M Y') }}</td></tr>
                                @endif
                                <tr><td class="k">Currency</td><td class="v">{{ $invoice->currency }}</td></tr>
                                @if ($invoice->salesman)
                                    <tr><td class="k">Salesperson</td><td class="v">{{ $invoice->salesman->name }}</td></tr>
                                @endif
                                @if ($invoice->reference_number)
                                    <tr><td class="k">Your reference</td><td class="v">{{ $invoice->reference_number }}</td></tr>
                                @endif
                            </table>
                        </td>
                    </tr>
                </table>

                {{-- Items --------------------------------------------------------- --}}
                <table class="items">
                    <thead>
                        <tr>
                            <th style="width: 52%;">Description</th>
                            <th class="num" style="width: 11%;">Qty</th>
                            <th class="num" style="width: 15%;">Unit price</th>
                            <th class="num" style="width: 10%;">Tax</th>
                            <th class="num" style="width: 12%;">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($invoice->items as $item)
                            @php
                                // No tax amount is stored per line, only a rate, so it is
                                // derived through DocumentItems rather than arithmetic in
                                // the template. Tax is a percentage of the line NET, not
                                // of the tax-inclusive total, so computing it as
                                // line_total x rate is wrong whenever a discount applies;
                                // and the rounding has to happen at the same step and
                                // scale as everywhere else or the printed rows stop
                                // adding up to the printed total.
                                $lineFigures = \App\Support\DocumentItems::lineTotals(
                                    (float) $item->qty,
                                    (float) $item->unit_price,
                                    (float) $item->discount_percent,
                                    (float) $item->tax_percent,
                                );
                            @endphp
                            <tr>
                                <td>
                                    <div class="item-name">{{ $item->description ?? $item->product?->name ?? 'Item' }}</div>
                                    @if ($item->product?->sku)
                                        <div class="item-sub">SKU {{ $item->product->sku }}</div>
                                    @endif
                                </td>
                                <td class="num">{{ rtrim(rtrim(number_format((float) $item->qty, 3), '0'), '.') }}</td>
                                <td class="num">{{ money($item->unit_price, $invoice->currency) }}</td>
                                <td class="num">{{ money($lineFigures['line_tax'], $invoice->currency) }}</td>
                                <td class="num">{{ money($item->line_total, $invoice->currency) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                {{--
                    Totals.

                    Discount is derived from the lines through
                    DocumentItems::lineTotals(), NOT read off the header discount
                    column. The header subtotal has already netted the per-line
                    discounts, so subtracting the header figure again double-counts
                    it and the row collapses to zero -- which is how the Discount
                    line went missing from the printed invoice entirely.

                    The grand total is printed as stored. It is the authoritative
                    computed figure the customer reconciles against, and a derived
                    total differing by a rounding cent would be worse than one that
                    looks slightly inconsistent with the rows above it.
                --}}
                @php
                    $derivedDiscount = 0.0;
                    $derivedTax = 0.0;

                    foreach ($invoice->items as $line) {
                        $f = \App\Support\DocumentItems::lineTotals(
                            (float) $line->qty,
                            (float) $line->unit_price,
                            (float) $line->discount_percent,
                            (float) $line->tax_percent,
                        );
                        $derivedDiscount += $f['line_discount'];
                        $derivedTax += $f['line_tax'];
                    }

                    $balance = round(
                        (float) $invoice->total - (float) $invoice->paid_amount - (float) $invoice->credit_applied,
                        2,
                    );
                @endphp

                <table class="totals">
                    <tr>
                        <td class="k">Subtotal</td>
                        <td class="v">{{ money($invoice->subtotal, $invoice->currency) }}</td>
                    </tr>
                    @if ($derivedDiscount > 0.004)
                        <tr>
                            <td class="k">Discount</td>
                            <td class="v">&minus;{{ money($derivedDiscount, $invoice->currency) }}</td>
                        </tr>
                    @endif
                    @if ($derivedTax > 0.004)
                        <tr>
                            <td class="k">Tax</td>
                            <td class="v">{{ money($derivedTax, $invoice->currency) }}</td>
                        </tr>
                    @endif
                    <tr class="grand">
                        <td class="k">Total</td>
                        <td class="v">{{ money($invoice->total, $invoice->currency) }}</td>
                    </tr>
                    @if ($balance > 0.004)
                        <tr class="due">
                            <td class="k">Amount due</td>
                            <td class="v">{{ money($balance, $invoice->currency) }}</td>
                        </tr>
                    @elseif ($balance < -0.004)
                        <tr class="settled">
                            <td class="k">Paid in full</td>
                            <td class="v">{{ money(abs($balance), $invoice->currency) }}</td>
                        </tr>
                    @endif
                </table>

                @if ($invoice->notes)
                    <div class="notes">
                        <div class="label" style="margin-bottom: 2px;">Notes</div>
                        <p>{!! nl2br(e($invoice->notes)) !!}</p>
                    </div>
                @endif

                <div class="footer">
                    {{ company_name() }}
                    @if (settings('company.email')) &middot; {{ settings('company.email') }} @endif
                    @if (settings('company.phone')) &middot; {{ settings('company.phone') }} @endif
                </div>

            </td>
        </tr>
        </tbody>
    </table>
</body>
</html>