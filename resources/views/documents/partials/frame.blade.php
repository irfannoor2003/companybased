<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $number }} - {{ company_name() }}</title>

    {{--
            The shared document frame -- the part of a printed document that is
            the company's, not the document's.

            `documents/pdf.blade.php` and the statement PDFs
            (`sales/statements/pdf.blade.php`,
            `suppliers/supplier_ledger/pdf.blade.php`) all include this file, so a
            statement genuinely prints in the invoice template the company chose
            instead of a look-alike that drifts. The canvas that follows this file
            is each document type's own.

            One template, five designs.

            `document_templates` and a Settings -> Templates CRUD shipped in August 2026 and
            nothing ever read them: this file hard-coded a single layout, so every template
            a customer built produced byte-identical output. `PdfLayout` is what closes that
            gap -- it resolves the default template for the document type and hands this
            template a STYLE and an ordered BLOCK list.

            FIVE STYLES, ONE FLOW
            -----------------------
            The style changes the *frame* and the *table treatment*. It deliberately does not
            change how content paginates. That is the whole constraint: DomPDF cannot repeat
            a table row across a page break, cannot count total pages, and cannot render
            `@page` margin boxes at `margin: 0`. A free-form template would let an operator
            break pagination; a style that only swaps the frame cannot.

            So every style below shares this one canvas flow, the one that is pagination-
            tested, and differs in:

                rail      full-height coloured band down the left, on every page
                classic   bordered header box, traditional left-aligned ledger
                modern    full-width brand masthead across the top
                minimal   no fills at all -- hairline rules, white space, ink
                banded    tinted table header, zebra rows, filled total bar

            Required variables: $layout (PdfLayout), $title, $number, $notes.
            The rail blocks additionally read $billTo when the rail style lists them.

            @include is safe inside a PDF template; Blade components (@x-) and
            layouts (@extends) are not, because DomPDF cannot resolve them.
        --}}
    <style>
        @php
            $style = $layout->style();

            // The TEMPLATE's colours go first -- they are what the operator picked on
            // Settings -> Templates, and what has to reach the page. Company branding
            // is only the fallback for a template that stores no colour of its own,
            // and PdfLayout already merges branding into the default colour set, so
            // the effective order is: template colour, else branding, else built-in.
            $brand = $layout->color('primary', settings('branding.primary_color', '#4f46e5'));
            $accent = $layout->color('accent', settings('branding.accent_color', '#0ea5e9'));

            $rail = darken_hex($brand, 62);
            $railSoft = mix_hex($rail, '#ffffff', 22);

            $ink = $layout->color('text', '#1b2130');
            $muted = '#79839a';
            $rule = '#e7eaf0';
            $panel = '#f6f8fb';

            /*
             * Minimal prints in ink only.
             *
             * Not a style flourish: it is the design a customer picks when their brand
             * colour does not survive being flooded across a page, and it must actually
             * drop the colour rather than tint everything a little.
             */
            if ($style === \App\Support\PdfLayout::STYLE_MINIMAL) {
                $ink = '#111111';
                $muted = '#6b6b6b';
                $rule = '#d8d8d8';
                $panel = '#fafafa';
            }

            /*
             * Blocks that have something to print.
             *
             * A block listed in the template is a request to print it, not a
             * guarantee that there is anything to say. Bank details need an account
             * number, terms need terms, notes need notes -- and a company that has
             * none of the three must get no block rather than a heading over
             * nothing, which is the single ugliest thing a document can do.
             *
             * Checked here, once, rather than in each loop, so the rail and the
             * canvas cannot disagree about it.
             */
            $blockReady = [
                'bank' => filled(settings('company.bank_account_number')),
                'terms' => filled(settings('company.payment_terms')),
                'notes' => filled($notes),
            ];
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

        /* @page margin 0 is what lets the rail bleed to the paper edge.

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

        /* ---- The rail design: fixed, full-bleed, repeated on every page ----

           `position: fixed` is the mechanism, not a decoration. As a table cell
           beside the canvas it rendered on page 1 only and could not be repeated,
           which was LOW-2.

           The width EXCLUDES the padding, and DomPDF adds the padding outside it
           regardless of `box-sizing` (it ignored border-box when this was written).
           At 28.5% the painted band came to 28.5% + 10mm = 33.3% of the page while
           the canvas inset is 33%, so the rail covered the first character of every
           heading -- "QUOTE" read as "Q U O T E", "Date" as "ate", on every page.

           23.5% + 10mm = 28.3%, which clears the 33% canvas inset with room to
           spare and keeps the rail visually about the same width. */
        .rail {
            position: fixed;
            top: 0;
            left: 0;
            box-sizing: border-box;
            width: 23.5%;
            height: 297mm;
            background-color: {{ $rail }};
            color: #ffffff;
            padding: 8mm 5mm 6mm 5mm;
        }

        /* ---- Masthead, classic and banded: a band across the top ----

           Fixed for the same reason the rail is: DomPDF re-emits a fixed block on
           every page, and a band that vanished after page 1 would leave continuation
           pages anonymous. */
        .masthead {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background-color: {{ $brand }};
            color: #ffffff;
            padding: 7mm 8mm;
        }

        .masthead-inner { width: 100%; border-collapse: collapse; }
        .masthead-inner td { border: none; padding: 0; vertical-align: middle; color: #ffffff; }
        .masthead-inner td.right { text-align: right; }

        .masthead-logo-panel { background-color: #ffffff; padding: 4px 6px; width: 26mm; }
        .masthead-name { font-size: 15px; font-weight: bold; letter-spacing: 0.02em; }
        .masthead-tagline { font-size: 8px; color: {{ mix_hex($brand, '#ffffff', 65) }}; margin-top: 2px; }
        .masthead-kind { font-size: 17px; font-weight: bold; letter-spacing: 0.14em; }
        .masthead-number { font-size: 9px; color: {{ mix_hex($brand, '#ffffff', 65) }}; margin-top: 2px; }

        /* ---- Minimal: no fill, one hairline ---- */
        .topline {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            border-bottom: 1px solid {{ $ink }};
            padding: 6mm 8mm 3mm;
            background-color: #ffffff;
        }

        .topline-inner { width: 100%; border-collapse: collapse; }
        .topline-inner td { border: none; padding: 0; color: {{ $ink }}; }
        .topline-inner td.right { text-align: right; color: {{ $muted }}; font-size: 8px; }

        .topline-name { font-size: 12px; font-weight: bold; letter-spacing: 0.08em; }
        .topline-kind { font-size: 10px; letter-spacing: 0.2em; color: {{ $muted }}; }

        /* The canvas: normal flow, inset to clear whatever frame the style uses.

           Margins rather than a table cell, because the canvas has to paginate
           freely -- a fixed-height cell cannot break across pages, which is what
           forced the rail into the page-1-only behaviour in the first place. */
        .canvas {
            margin-left: 33%;
            margin-right: 5%;
            padding-top: 6mm;
        }

        /* Every style other than the rail runs the full width of the sheet.

           Scoped off the body class rather than a second class on the canvas, so
           `.canvas` stays one rule and its element stays a plain canvas div -- which
           is what DocumentRailTest pins, and rightly: that test exists so nobody puts
           the rail back inside a table cell, and it should not be rewritten to
           accommodate a design that does not need changing. */
        .style-classic .canvas,
        .style-modern .canvas,
        .style-minimal .canvas,
        .style-banded .canvas {
            margin-left: 8%;
            margin-right: 8%;
        }

        .style-classic .canvas { padding-top: 30mm; }
        .style-modern .canvas,
        .style-banded .canvas { padding-top: 34mm; }
        .style-minimal .canvas { padding-top: 16mm; }

        /* Classic draws its own bordered header box in the flow, because nothing about
           a hairline design should be a filled panel. */
        .classic-box {
            border: 1px solid {{ $ink }};
            padding: 7px 9px;
            margin-bottom: 8mm;
        }

        table.rail-inner { width: 100%; border-collapse: collapse; table-layout: fixed; }
        table.rail-inner td { border: none; padding: 0; vertical-align: top; color: #ffffff; }
        table.rail-inner tr.spacer td { height: 168mm; }
        table.rail-inner tr.rule td { border-top: 1px solid {{ $railSoft }}; padding: 11px 0; }
        table.rail-inner tr.block td { padding-bottom: 10px; }

        /* Width EXCLUDES the padding. DomPDF adds padding outside a declared width
           regardless of `box-sizing`, so 34mm + 2x7mm is 48mm of panel and the image
           is set to 26mm to sit inside it with room to spare. */
        table.rail-logo-panel {
            width: 34mm;
            background-color: #ffffff;
            border-collapse: collapse;
            margin-bottom: 8px;
        }
        table.rail-logo-panel td {
            border: none;
            padding: 5px 7px;
            text-align: left;
        }
        .rail-name { font-size: 15px; font-weight: bold; color: #ffffff; letter-spacing: 0.02em; line-height: 1.25; }
        .rail-tagline { font-size: 8px; color: {{ $railSoft }}; margin-top: 3px; letter-spacing: 0.04em; }
        .rail-label { font-size: 8px; font-weight: bold; color: {{ $muted }}; letter-spacing: 0.16em; text-transform: uppercase; margin-bottom: 5px; }
        .rail-name-strong { font-size: 9.5px; font-weight: bold; color: {{ $ink }}; margin-bottom: 2px; }
        .rail-line { font-size: 8px; color: {{ $muted }}; line-height: 1.6; }
        .rail-terms { font-size: 8px; color: {{ $muted }}; line-height: 1.65; }
        .rail-thanks { font-size: 9.5px; font-weight: bold; color: #ffffff; letter-spacing: 0.14em; text-transform: uppercase; line-height: 1.7; padding-top: 6mm; }

        /* Inside the rail the labels and lines must be light, not ink. */
        .rail .rail-label { color: {{ $railSoft }}; }
        .rail .rail-name-strong { color: #ffffff; }
        .rail .rail-line { color: {{ $railSoft }}; }
        .rail .rail-terms { color: {{ $railSoft }}; }

        /* Party / bank / terms rendered in the canvas rather than the rail. */
        .canvas-block { margin-bottom: 7mm; page-break-inside: avoid; }
        .canvas-block .rail-name-strong { font-size: 11px; }
        .canvas-block .rail-line { font-size: 9px; }

        .doc-kind { font-size: 25px; font-weight: normal; letter-spacing: 0.22em; color: {{ $ink }}; line-height: 1.1; }
        .doc-number { font-size: 9px; color: {{ $muted }}; margin-top: 3px; }
        .doc-date { font-size: 9.5px; color: {{ $ink }}; margin-top: 11mm; }
        .doc-due-label { font-size: 9.5px; color: {{ $muted }}; margin-top: 5mm; }
        .doc-due { font-size: 19px; font-weight: bold; color: {{ $ink }}; margin-top: 1px; }

        /* A document that carries no money at all -- a delivery note -- shows the
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

        /* Banded: tinted header and zebra rows. The zebra uses a fixed tint rather than
           the brand colour so a strong brand cannot produce rows too dark to read. */
        .style-banded table.items thead th {
            background-color: {{ mix_hex($brand, '#ffffff', 88) }};
            border: 1px solid {{ $brand }};
            color: {{ darken_hex($brand, 30) }};
        }
        .style-banded table.items tbody tr.alt td { background-color: {{ $panel }}; }

        /* Minimal: no vertical rules at all. */
        .style-minimal table.items thead th {
            border: none;
            border-bottom: 1px solid {{ $ink }};
            padding: 5px 6px 4px;
        }
        .style-minimal table.items tbody td { border-bottom: 1px solid {{ $rule }}; padding: 6px; }

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
        table.totals tr.grand td {
            border: 1px solid {{ $brand }};
            color: {{ $brand }};
            font-weight: bold;
            font-size: 11.5px;
            padding: 7px 8px;
        }

        /* Banded fills the grand total rather than outlining it. */
        .style-banded table.totals tr.grand td {
            background-color: {{ $brand }};
            color: #ffffff;
            border: 1px solid {{ $brand }};
        }

        /* Minimal rules the total on ink alone. */
        .style-minimal table.totals tr.grand td {
            border: none;
            border-top: 2px solid {{ $ink }};
            border-bottom: 2px solid {{ $ink }};
            color: {{ $ink }};
        }

        .settlement { page-break-inside: avoid; }

        table.sign { width: 100%; border-collapse: collapse; margin-top: 6mm; }
        table.sign td { border: none; vertical-align: bottom; }
        table.sign td.mark { width: 62mm; padding-right: 8mm; }
        table.sign .sign-name { font-size: 12px; font-style: italic; color: {{ $ink }}; }
        table.sign .sign-role { font-size: 8.5px; color: {{ $muted }}; margin-top: 2px; }
        table.sign .sign-rule { border-bottom: 1px solid {{ $rule }}; margin-bottom: 4px; height: 12px; }

        .notes { margin-top: 6mm; background-color: {{ $panel }}; padding: 7px 9px; page-break-inside: avoid; }
        .notes p { margin: 0; font-size: 9px; color: #3c4455; }

        /* Minimal prints notes as plain text, because a filled panel is the one thing
           it is trying not to be. */
        .style-minimal .notes { background-color: #ffffff; padding: 0; border-top: 1px solid {{ $rule }}; }

        table.footer { width: 100%; border-collapse: collapse; margin-top: 8mm; page-break-inside: avoid; }
        table.footer td { border-top: 1px solid {{ $rule }}; padding-top: 5px; font-size: 8px; color: {{ $muted }}; vertical-align: top; }
        table.footer td + td { padding-left: 5mm; }

        {{-- The operator's custom HTML is a block in the flow, not frame furniture:
             small type, a rule of its own, and anchored by a class they can target
             from their custom CSS. --}}
        .custom-html { margin: 4mm 0; font-size: 9px; color: {{ $ink }}; }
        .custom-html p { margin: 0 0 2mm 0; }
    </style>

    {{--
            The template's custom stylesheet, printed LAST.

            This used to be stored on the template and never spoken: the field
            existed on the settings form, the bytes landed in the database, and
            the design never read them -- the same lie the blocks used to tell.
            A second <style> tag rather than an @append into the one above, so the
            cascade is real: whatever is in here overrides the built-in design
            without needing to win specificity fights with it.
        --}}
    @if ($layout->templateCss() !== null)
        <style>{!! $layout->templateCss() !!}</style>
    @endif
</head>
<body class="style-{{ $style }}">

    {{--
            Fixed, like every other frame element, so it repeats. DomPDF cannot repeat a
            table row across a page break, so anything that has to appear on page 2 has to
            leave the flow. That is the whole of LOW-2.
        --}}
    <div class="page-number"></div>

    @if ($style === \App\Support\PdfLayout::STYLE_RAIL)
        <div class="rail">
            <table class="rail-inner">
                <tr class="block">
                    <td>
                        @if (company_logo_path())
                            {{--
                                    A one-cell table, not a div.

                                    DomPDF lays a div's box out with its own idea of
                                    `box-sizing`, and the declared 34mm panel width with
                                    7mm of padding each side left the image no room: the
                                    logo was painted into a sliver and the rail printed a
                                    plain white rectangle where the brand mark belonged,
                                    on every page of every document. A table cell with a
                                    fixed width is the one construct this engine sizes
                                    predictably, which is why every column in these
                                    templates is a percentage of a `<th>`.
                                --}}
                            <table class="rail-logo-panel">
                                <tr>
                                    <td>
                                        <img src="{{ company_logo_path() }}" alt="{{ company_name() }}" style="width: 26mm;">
                                    </td>
                                </tr>
                            </table>
                        @endif
                        <div class="rail-name">{{ company_name() }}</div>
                        @if (settings('company.tagline'))
                            <div class="rail-tagline">{{ settings('company.tagline') }}</div>
                        @endif
                    </td>
                </tr>

                <tr class="rule"><td></td></tr>

                {{--
                        Bill-to, bank details and payment terms, in the template's order.

                        They travel down the rail rather than through the canvas because
                        they are the three an accounts department reads before signing,
                        and on the rail design they are in the reader's eye from the first
                        line of the document rather than after the items.
                    --}}
                @foreach ($layout->railBlocks() as $railBlock)
                    @continue(! ($blockReady[$railBlock] ?? true))
                    <tr class="block"><td>@include('documents.blocks.'.$railBlock)</td></tr>
                    <tr class="rule"><td></td></tr>
                @endforeach

                <tr class="spacer"><td></td></tr>

                <tr>
                    <td>
                        <div class="rail-thanks">Thank you<br>for your business</div>
                    </td>
                </tr>
            </table>
        </div>
    @elseif ($style === \App\Support\PdfLayout::STYLE_MINIMAL)
        <div class="topline">
            <table class="topline-inner">
                <tr>
                    <td>
                        <div class="topline-name">{{ company_name() }}</div>
                    </td>
                    <td class="right">
                        @if ($layout->has('title'))
                            {{ strtoupper($title) }} #{{ $number }}
                        @endif
                    </td>
                </tr>
            </table>
        </div>
    @else
        {{-- classic, modern and banded share a top band; they differ in colour and in
             what the band says. --}}
        <div class="masthead" @if ($style === \App\Support\PdfLayout::STYLE_CLASSIC) style="background-color: {{ $ink }};" @endif>
            <table class="masthead-inner">
                <tr>
                    <td>
                        @if (company_logo_path())
                            <div class="masthead-logo-panel">
                                <img src="{{ company_logo_path() }}" alt="{{ company_name() }}" style="width: 22mm;">
                            </div>
                        @endif
                        <div class="masthead-name">{{ company_name() }}</div>
                        @if (settings('company.tagline'))
                            <div class="masthead-tagline">{{ settings('company.tagline') }}</div>
                        @endif
                    </td>
                    <td class="right">
                        @if ($layout->has('title'))
                            <div class="masthead-kind">{{ strtoupper($title) }}</div>
                            <div class="masthead-number"># {{ $number }}</div>
                        @endif
                    </td>
                </tr>
            </table>
        </div>
    @endif

    <div class="canvas">

        @if ($layout->headerHtml() !== null)
            {{--
                    The operator's custom header block, printed at the top of the
                    canvas flow in the template's own order. The frame carries it,
                    so both consumers of this frame -- the documents and the
                    statements -- get it without a second wire-up.
                --}}
            <div class="custom-html custom-html-header">{!! $layout->headerHtml() !!}</div>
        @endif

