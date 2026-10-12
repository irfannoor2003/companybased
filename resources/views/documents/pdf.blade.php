<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $number }} - {{ company_name() }}</title>

    {{--
        One document design for every document type: quotes, orders, delivery
        notes, credit notes, recurring invoices, purchase quotes, purchase
        orders, debit notes and purchase invoices.

        This replaces `pdf/document.blade.php` and the four separate purchase
        invoice templates, which between them were around 1,100 lines of
        near-duplicate CSS. They were independently authored, which is how an
        invoice and a quote from the same company came to print with different
        letterheads, different rules and different totals blocks — and how the
        credit note lost its discount line entirely while the invoice kept it.

        Layout matches sales/invoices/partials/pdf-rail.blade.php exactly. The
        two cannot share a file: DomPDF cannot run Blade layouts or components,
        and the invoice carries a `credit_applied` mechanic the other document
        types have no equivalent of. They do share the arithmetic and the visual
        language, which is what has to match.

        Colours and metrics are declared with the same helpers the invoice uses,
        so a change to the brand or the sheet geometry lands in both.
    --}}
    <style>
        @php
            $brand = settings('branding.primary_color', '#4f46e5');
            $rail = darken_hex($brand, 62);
            $railSoft = mix_hex($rail, '#ffffff', 22);

            $ink = '#1b2130';
            $muted = '#79839a';
            $rule = '#e7eaf0';
            $panel = '#f6f8fb';
        @endphp

        * { box-sizing: border-box; }

        body {
            font-family: 'DejaVu Sans', Helvetica, Arial, sans-serif;
            font-size: 10px;
            line-height: 1.5;
            color: {{ $ink }};
            margin: 0;
            padding: 0;
        }

        /* @page margin 0 is what lets the left rail bleed to the paper edge.

           The `@bottom-center` margin box does NOT render -- DomPDF skips margin
           boxes when the page margin is 0, which the full-bleed rail requires.
           Measured rather than assumed: with `@page { margin: 0; @bottom-right { ... } }`
           nothing is drawn on any page.

           Page numbering therefore uses the same mechanism as the rail: a
           `position: fixed` block with generated content, which DomPDF DOES
           re-emit per page. `content: counter(page)` gives the correct number on
           each page ("Page 1", "Page 2", ...).

           Deliberately not "Page N of M": DomPDF resolves `counter(pages)` to 0
           because the total is not known while pages are being laid out, so the
           printed text came out as "Page 3 of 0". A wrong total is worse than no
           total. */
        @page { margin: 0; }

        /* Fixed, like the rail, so it repeats. Inset from the right to sit inside
           the canvas rather than under the rail, and lifted off the paper edge. */
        .page-number {
            position: fixed;
            bottom: 6mm;
            right: 5%;
            font-size: 7.5px;
            color: {{ $muted }};
        }

        .page-number::after {
            content: "Page " counter(page);
        }

        /* The rail: fixed, full-bleed, repeated on every page by DomPDF.

           `position: fixed` is the mechanism, not a decoration. As a table cell
           beside the canvas it rendered on page 1 only and could not be repeated,
           which was LOW-2. */
        .rail {
            position: fixed;
            top: 0;
            left: 0;
            /* The width EXCLUDES the padding, and DomPDF adds the padding outside
               it regardless of `box-sizing` (it ignored border-box when this was
               written). At 28.5% the painted band came to 28.5% + 10mm = 33.3% of
               the page while the canvas inset is 33%, so the rail covered the first
               character of every heading -- "QUOTE" read as "Q U O T E", "Date" as
               "ate", on every page.

               23.5% + 10mm = 28.3%, which clears the 33% canvas inset with room to
               spare and keeps the rail visually about the same width. */
            box-sizing: border-box;
            width: 23.5%;
            height: 297mm;
            background-color: {{ $rail }};
            color: #ffffff;
            padding: 8mm 5mm 6mm 5mm;
        }

        /* The canvas: normal flow, inset to clear the fixed rail.

           Margins rather than a table cell, because the canvas has to paginate
           freely -- a fixed-height cell cannot break across pages, which is what
           forced the rail into the page-1-only behaviour in the first place. */
        .canvas {
            margin-left: 33%;
            margin-right: 5%;
            padding-top: 6mm;
        }

        table.rail-inner { width: 100%; border-collapse: collapse; table-layout: fixed; }
        table.rail-inner td { border: none; padding: 0; vertical-align: top; color: #ffffff; }
        table.rail-inner tr.spacer td { height: 168mm; }
        table.rail-inner tr.rule td { border-top: 1px solid {{ $railSoft }}; padding: 11px 0; }
        table.rail-inner tr.block td { padding-bottom: 10px; }

        .rail-logo-panel { background-color: #ffffff; padding: 5px 7px; margin-bottom: 8px; width: 34mm; }
        .rail-name { font-size: 15px; font-weight: bold; color: #ffffff; letter-spacing: 0.02em; line-height: 1.25; }
        .rail-tagline { font-size: 8px; color: {{ $railSoft }}; margin-top: 3px; letter-spacing: 0.04em; }
        .rail-label { font-size: 8px; font-weight: bold; color: {{ $railSoft }}; letter-spacing: 0.16em; text-transform: uppercase; margin-bottom: 5px; }
        .rail-name-strong { font-size: 9.5px; font-weight: bold; color: #ffffff; margin-bottom: 2px; }
        .rail-line { font-size: 8px; color: {{ $railSoft }}; line-height: 1.6; }
        .rail-terms { font-size: 8px; color: {{ $railSoft }}; line-height: 1.65; }
        .rail-thanks { font-size: 9.5px; font-weight: bold; color: #ffffff; letter-spacing: 0.14em; text-transform: uppercase; line-height: 1.7; padding-top: 6mm; }

        .doc-kind { font-size: 25px; font-weight: normal; letter-spacing: 0.22em; color: {{ $ink }}; line-height: 1.1; }
        .doc-number { font-size: 9px; color: {{ $muted }}; margin-top: 3px; }
        .doc-date { font-size: 9.5px; color: {{ $ink }}; margin-top: 11mm; }
        .doc-due-label { font-size: 9.5px; color: {{ $muted }}; margin-top: 5mm; }
        .doc-due { font-size: 19px; font-weight: bold; color: {{ $ink }}; margin-top: 1px; }

        /* A document that carries no money at all — a delivery note — shows the
           item count as its headline figure instead of a total that would read
           as zero. */
        .doc-meta { margin-top: 4mm; }
        .doc-meta-row { padding: 1.6px 0; }
        .doc-meta-label { display: inline-block; width: 46%; color: {{ $muted }}; font-size: 9px; }
        .doc-meta-value { display: inline-block; width: 52%; color: {{ $ink }}; font-weight: bold; font-size: 9px; }

        table.items { width: 100%; border-collapse: collapse; table-layout: fixed; margin-top: 9mm; }

        table.items thead th {
            border: 1px solid {{ $ink }};
            color: {{ $ink }};
            text-align: left;
            padding: 6px 7px;
            font-size: 7.5px;
            font-weight: bold;
            letter-spacing: 0.1em;
            text-transform: uppercase;
        }
        table.items thead th.num { text-align: right; }

        table.items tbody td {
            padding: 7px;
            font-size: 9.5px;
            color: #333a4a;
            border-bottom: 1px solid {{ $rule }};
            vertical-align: top;
        }
        table.items tbody td.num { text-align: right; white-space: nowrap; }
        table.items tbody td.idx { width: 7%; color: {{ $muted }}; }
        table.items tbody td.desc { width: 33%; }
        table.items tbody td.total { font-weight: bold; color: {{ $ink }}; }
        table.items tbody tr { page-break-inside: avoid; }

        /* The totals block sits in the right half of the canvas, so its width has to
           be relative to the canvas rather than an absolute 74mm plus a 50mm gutter.
           74mm + 50mm = 124mm is wider than the canvas cell on A4 once the rail
           (28.5%) and the canvas's own 6mm left padding are taken off it, which
           pushed the right-hand value column past the paper edge and clipped
           "180.00" to "180.0".

           Percentages all the way down: the gutter takes half, the totals take the
           other half, and neither can overflow the cell it lives in. */
        table.totals-wrap { width: 100%; border-collapse: collapse; table-layout: fixed; }
        table.totals-wrap td { border: none; padding: 0; vertical-align: top; }
        table.totals-wrap td.gutter { width: 38%; }

        table.totals { width: 62%; border-collapse: collapse; }
        table.totals td { padding: 4px 8px; font-size: 9.5px; }
        table.totals td.label { text-align: left; color: {{ $muted }}; }
        table.totals td.value { text-align: right; color: {{ $ink }}; white-space: nowrap; }
        table.totals tr.grand td { border: 1px solid {{ $brand }}; color: {{ $brand }}; font-weight: bold; font-size: 11.5px; padding: 7px 8px; }

        .settlement { page-break-inside: avoid; }

        table.sign { width: 100%; border-collapse: collapse; margin-top: 6mm; }
        table.sign td { border: none; vertical-align: bottom; }
        table.sign td.mark { width: 62mm; padding-right: 8mm; }
        table.sign .sign-name { font-size: 12px; font-style: italic; color: {{ $ink }}; }
        table.sign .sign-role { font-size: 8.5px; color: {{ $muted }}; margin-top: 2px; }
        table.sign .sign-rule { border-bottom: 1px solid {{ $rule }}; margin-bottom: 4px; height: 12px; }

        .notes { margin-top: 6mm; background-color: {{ $panel }}; padding: 7px 9px; page-break-inside: avoid; }
        .notes .rail-label { color: {{ $muted }}; }
        .notes p { margin: 0; font-size: 9px; color: #3c4455; }

        table.footer { width: 100%; border-collapse: collapse; margin-top: 8mm; page-break-inside: avoid; }
        table.footer td { border-top: 1px solid {{ $rule }}; padding-top: 5px; font-size: 8px; color: {{ $muted }}; vertical-align: top; }
        table.footer td + td { padding-left: 5mm; }
    </style>
</head>
<body>
    {{--
        The rail is a `position: fixed` block, NOT a table cell.

        That is the whole of LOW-2. As a 296mm `<td>` beside the canvas it could
        only ever render on page 1: DomPDF cannot repeat a table row across a page
        break, so page 2 of a long document came out with no brand, no company name
        and no bill-to -- an anonymous sheet of numbers.

        DomPDF does re-emit a fixed-position block on every page, so the rail sits
        outside the flow entirely and the canvas is inset with a margin to clear it.
        Verified by rendering a 48-line quote: page 2 carries the rail, the boxed
        table header and the footer.

        An earlier attempt added a fixed band but LEFT the rail cell in place, and
        DomPDF then re-emitted the entire page-1 layout on every continuation page
        and pushed a 48-line document from 2 pages to 3. The rail has to leave the
        table, not be duplicated beside it.
    --}}
    <div class="page-number"></div>

    <div class="rail">
        <table class="rail-inner">
            <tr class="block">
                <td>
                    @if (settings('branding.logo'))
                        <div class="rail-logo-panel">
                            <img src="{{ storage_path('app/public/'.settings('branding.logo')) }}" alt="{{ company_name() }}" style="width: 28mm;">
                        </div>
                    @endif
                    <div class="rail-name">{{ company_name() }}</div>
                    @if (settings('company.tagline'))
                        <div class="rail-tagline">{{ settings('company.tagline') }}</div>
                    @endif
                </td>
            </tr>

            <tr class="rule"><td></td></tr>

            <tr class="block">
                <td>
                    <div class="rail-label">{{ $billTo['heading'] ?? 'Bill to' }}</div>
                    <div class="rail-name-strong">{{ $billTo['name'] ?: '-' }}</div>
                    @if (filled($billTo['contact']))
                        <div class="rail-line">Attn: {{ $billTo['contact'] }}</div>
                    @endif
                    @if (filled($billTo['address']))
                        <div class="rail-line">{!! nl2br(e($billTo['address'])) !!}</div>
                    @endif
                    @if (filled($billTo['code']))
                        <div class="rail-line">Code: {{ $billTo['code'] }}</div>
                    @endif
                    @if (filled($billTo['tax']))
                        <div class="rail-line">Tax no: {{ $billTo['tax'] }}</div>
                    @endif
                </td>
            </tr>

            @if (filled(settings('company.bank_account_number')))
                <tr class="rule"><td></td></tr>
                <tr class="block">
                    <td>
                        <div class="rail-label">Bank</div>
                        @if (filled(settings('company.bank_name')))
                            <div class="rail-line">{{ settings('company.bank_name') }}</div>
                        @endif
                        <div class="rail-line">A/C {{ settings('company.bank_account_number') }}</div>
                    </td>
                </tr>
            @endif

            @if (filled(settings('company.payment_terms')))
                <tr class="rule"><td></td></tr>
                <tr class="block">
                    <td>
                        <div class="rail-label">Terms</div>
                        <div class="rail-terms">{!! nl2br(e(settings('company.payment_terms'))) !!}</div>
                    </td>
                </tr>
            @endif

            <tr class="spacer"><td></td></tr>

            <tr>
                <td>
                    <div class="rail-thanks">Thank you<br>for your business</div>
                </td>
            </tr>
        </table>
    </div>

    <div class="canvas">
        <div class="doc-kind">{{ strtoupper($title) }}</div>
        <div class="doc-number">{{ $title }}# {{ $number }}</div>

        @if ($hasPricing)
            <div class="doc-date">Date: {{ $doc->issue_date?->format('d/m/Y') ?: '-' }}</div>
            <div class="doc-due-label">Total</div>
            <div class="doc-due">{{ money((float) ($doc->total ?: 0), $currency) }}</div>
        @else
            <div class="doc-date">Date: {{ $doc->issue_date?->format('d/m/Y') ?: '-' }}</div>
            <div class="doc-due-label">Items</div>
            <div class="doc-due">{{ count($rows) }}</div>
        @endif

        @if (filled($referenceNumber))
            <div class="doc-number">Your ref: {{ $referenceNumber }}</div>
        @endif

        <div class="doc-meta">
            @foreach ($meta as $metaRow)
                @if ($metaRow['show'] && filled($metaRow['value']) && $metaRow['value'] !== '-')
                    <div class="doc-meta-row">
                        <span class="doc-meta-label">{{ $metaRow['label'] }}</span>
                        <span class="doc-meta-value">{{ $metaRow['value'] }}</span>
                    </div>
                @endif
            @endforeach
        </div>

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
                    <tr>
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

        @if ($hasPricing)
            <div class="settlement">
                <table class="totals-wrap">
                    <tr>
                        <td class="gutter"></td>
                        <td>
                            <table class="totals">
                                @foreach ($totals as $totalRow)
                                    <tr @if ($totalRow['total']) class="grand" @endif>
                                        <td class="label">{{ $totalRow['label'] }}</td>
                                        <td class="value">
                                            @if ($totalRow['total'] && $totalRow['value'] < 0)
                                                -{{ number_format(abs((float) $totalRow['value']), 2) }}
                                            @else
                                                {{ number_format((float) $totalRow['value'], 2) }}
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </table>
                        </td>
                    </tr>
                </table>

                <table class="sign">
                    <tr>
                        <td class="mark">
                            <div class="sign-rule"></div>
                            <div class="sign-name">{{ company_name() }}</div>
                            <div class="sign-role">Authorised signature</div>
                        </td>
                        <td></td>
                    </tr>
                </table>
            </div>
        @endif

        @if (filled($notes))
            <div class="notes">
                <div class="rail-label">Notes</div>
                <p>{!! nl2br(e($notes)) !!}</p>
            </div>
        @endif

        <table class="footer">
            <tr>
                <td>{!! nl2br(e(settings('company.address', ''))) !!}</td>
                <td>
                    @if (filled(settings('company.phone')))P: {{ settings('company.phone') }}<br>@endif
                    @if (filled(settings('company.email')))M: {{ settings('company.email') }}@endif
                </td>
                <td>
                    @if (filled(settings('company.website'))){{ settings('company.website') }}<br>@endif
                    @if (filled(settings('company.tax_number')))Tax no. {{ settings('company.tax_number') }}@endif
                </td>
            </tr>
        </table>
    </div>
</body>
</html>
