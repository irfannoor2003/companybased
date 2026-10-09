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

           The rail itself is a single 168mm spacer cell, so it renders on page 1
           only -- DomPDF has no way to repeat a table row across pages. That is the
           accepted LOW-2 limitation and is why the rail is decorative rather than
           load-bearing.

           What CAN repeat is a margin box, so continuation pages at least carry
           page numbers and the company identity. Without it a page 2 falling out of
           a multi-page invoice is an anonymous sheet of numbers: the reader cannot
           tell which document it belongs to, or that there is more to come. */
        @page {
            margin: 0;

            @bottom-center {
                content: "Page " counter(page) " of " counter(pages);
                font-family: 'DejaVu Sans', Helvetica, Arial, sans-serif;
                font-size: 8px;
                color: {{ $muted }};
            }
        }

        /* Percentages, not millimetres: DomPDF ignores a `width` in mm on a cell
           and falls back to an even split, which pushed the canvas off the page.
           28.5% + 61% = 89.5%, leaving 10.5% of slack.

           The slack is not decoration. The canvas cell carries 6mm of left padding
           (below) and the nested tables inside it add their own, and in a fixed
           table layout DomPDF adds padding ON TOP of the declared width rather than
           inside it. At 67% that overflow pushed the right-hand column of the
           totals block past the paper edge, clipping "180.00" to "180.0". */
        table.sheet {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            margin: 0;
            padding: 0;
        }
        table.sheet td { border: none; padding: 0; vertical-align: top; }

        /* The canvas needs its own left padding. `table-layout: fixed` reserves
           28.5% + 69% = 97.5% of the page for the two cells, but DomPDF resolves
           the rail's fixed 296mm height and the nested rail table before it settles
           the canvas column, so the canvas content started flush against the rail and
           the rail painted over its first characters -- "QUOTE" rendered as "UOTE",
           "Date" as "ate", on every single page of every document.

           The padding is applied to the cell rather than to each block inside it, so
           it holds for the heading, the meta rows and the line table alike without
           every one of them needing its own offset.

           6mm, not 8mm: the canvas cell's declared width plus this padding plus the
           table's own internal padding has to stay inside the page, and at 8mm the
           right-hand column of the totals block ("180.00") was pushed past the edge
           and clipped. The left rail overlap was about 2-3mm, so 6mm clears it with
           room to spare. */
        table.sheet td.canvas { padding-left: 6mm; }

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
    <table class="sheet">
        <tbody>
            <tr>
                <td class="rail" style="width: 28.5%; height: 296mm; background-color: {{ $rail }};">
                    {{-- Background comes from .rail-band, which repeats on every page.
                         Painting it here as well would double up on page 1. --}}
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

                        @if (filled(settings('company.bank_account_name')) || filled(settings('company.bank_account_number')) || filled(settings('company.payment_instructions')))
                            <tr class="rule"><td></td></tr>
                            <tr class="block">
                                <td>
                                    <div class="rail-label">Payment</div>
                                    @if (filled(settings('company.bank_account_name')))
                                        <div class="rail-name-strong">{{ settings('company.bank_account_name') }}</div>
                                    @endif
                                    @if (filled(settings('company.bank_name')))
                                        <div class="rail-line">{{ settings('company.bank_name') }}</div>
                                    @endif
                                    @if (filled(settings('company.bank_account_number')))
                                        <div class="rail-line">A/C {{ settings('company.bank_account_number') }}</div>
                                    @endif
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
                </td>

                <td class="canvas" style="width: 61%;">
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
                </td>
            </tr>
        </tbody>
    </table>
</body>
</html>