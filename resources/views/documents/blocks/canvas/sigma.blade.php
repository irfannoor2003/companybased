{{--
    The real SigmaShop document body, ported from the inventory project.

    Tables (not flex/grid) because DomPDF lays those out reliably: a flat
    header with a brand chip, a three-column details block, an items table with
    a solid brand-coloured header, a right-floated totals table, notes and a
    centred thank-you footer. This block replaces the standard canvas blocks --
    a template whose block list contains only `sigma` prints this and nothing
    else, so there is no duplicate letterhead from the frame.
--}}
@php
    $sigmaBrand = $layout->color('primary', settings('branding.primary_color', '#1e3a5f'));
    $sigmaCompany = company_name();
    $party = $billTo ?? ['name' => null, 'code' => null, 'address' => null, 'tax' => null, 'contact' => null];
    $sigmaTable = $table ?? null;
@endphp
<div class="sigma-doc">
    {{-- Header: logo + brand chip on the left, company details on the right. --}}
    <table class="sigma-header">
        <tr>
            <td style="width: 58%; vertical-align: middle;">
                @if (company_logo_path())
                    <img src="{{ company_logo_path() }}" alt="{{ $sigmaCompany }}" style="max-width: 32mm; max-height: 16mm;">
                @endif
                <div class="sigma-chip" style="background-color: {{ $sigmaBrand }}; color: #ffffff; padding: 3px 8px; font-size: 8px; font-weight: bold; letter-spacing: 0.1em; text-transform: uppercase;">{{ $sigmaCompany }}</div>
            </td>
            <td style="width: 42%; vertical-align: middle; text-align: right; font-size: 10px; line-height: 1.5; color: #222;">
                <strong style="font-size: 12px;">{{ $sigmaCompany }}</strong><br>
                @if (settings('company.address')) {!! nl2br(e(settings('company.address'))) !!}<br> @endif
                @if (settings('company.phone')) Tel: {{ settings('company.phone') }}<br> @endif
                @if (settings('company.email')) {{ settings('company.email') }}<br> @endif
                @if (settings('company.tax_number')) Tax: {{ settings('company.tax_number') }} @endif
            </td>
        </tr>
    </table>

    {{--
        The document's own identity. Statements, ledgers, payslips and exports borrow
        the invoice template -- and therefore this body -- but they are not invoices.
        This design hides the frame's masthead, so without a title here a statement
        would say only who issued it and never what it is.
    --}}
    @if (filled($title ?? null))
        <div class="sigma-doc-title" style="margin-top: 4mm; font-size: 14px; font-weight: bold; letter-spacing: 0.12em; text-transform: uppercase; color: {{ $sigmaBrand }};">{{ $title }}</div>
    @endif

    {{-- Three-column details: billing / delivery / document facts. --}}
    <table class="sigma-info" style="width: 100%; border-collapse: collapse; margin-top: 6mm;">
        <tr>
            <td style="width: 34%; vertical-align: top; padding-right: 6mm;">
                <div class="sigma-block-title" style="font-size: 9px; font-weight: bold; letter-spacing: 0.08em; text-transform: uppercase; color: {{ $sigmaBrand }}; margin-bottom: 3px;">{{ filled($party['heading'] ?? null) ? $party['heading'] : 'Billing / Party' }}</div>
                <strong>{{ $party['name'] ?: '—' }}</strong><br>
                @if ($party['code']) Code: {{ $party['code'] }}<br> @endif
                @if ($party['address']) {!! nl2br(e($party['address'])) !!}<br> @endif
                @if ($party['tax']) Tax: {{ $party['tax'] }} @endif
            </td>
            <td style="width: 33%; vertical-align: top; padding-right: 6mm;">
                <div class="sigma-block-title" style="font-size: 9px; font-weight: bold; letter-spacing: 0.08em; text-transform: uppercase; color: {{ $sigmaBrand }}; margin-bottom: 3px;">Shipping / Delivery</div>
                <strong>{{ $party['name'] ?: '—' }}</strong><br>
                @if ($party['address']) {!! nl2br(e($party['address'])) !!} @endif
            </td>
            <td style="width: 33%; vertical-align: top;">
                <div class="sigma-block-title" style="font-size: 9px; font-weight: bold; letter-spacing: 0.08em; text-transform: uppercase; color: {{ $sigmaBrand }}; margin-bottom: 3px;">Document details</div>
                <table style="width: 100%; font-size: 9px;">
                    @if (filled($number ?? null))
                        <tr><td style="color: #6b7280;">Number</td><td style="text-align: right;"><strong>{{ $number }}</strong></td></tr>
                    @endif
                    @if (filled($dateLabel ?? null))
                        <tr><td style="color: #6b7280;">{{ $dateLabel }}</td><td style="text-align: right;">{{ $dateValue ?? '-' }}</td></tr>
                    @endif
                    {{--
                        Print every meta row the payload supplies, not a fixed few.
                        An invoice carries a due date and a salesperson here; a
                        statement carries its opening balance and coverage. A
                        document that borrows this template must not lose its own
                        facts just because they were not invoice facts.
                    --}}
                    @foreach (($meta ?? []) as $sigmaRow)
                        @if (($sigmaRow['show'] ?? true) && filled($sigmaRow['value'] ?? null) && ($sigmaRow['value'] ?? null) !== '-')
                            <tr><td style="color: #6b7280;">{{ $sigmaRow['label'] }}</td><td style="text-align: right;">{{ $sigmaRow['value'] }}</td></tr>
                        @endif
                    @endforeach
                </table>
            </td>
        </tr>
    </table>

    {{--
        Items: solid brand-coloured header, as in the inventory design.

        Statements, ledgers and exports arrive with a generic `table` payload
        rather than invoice lines. Print that here instead of the product table,
        or a statement prints "No line items" exactly where its ledger belongs.
    --}}
    @if (filled($body ?? null))
        {{--
            A payslip (or any document with a body of its own) carries its own
            detail layout -- earnings, deductions, the attendance breakdown. The
            SigmaShop template's block list is just `sigma`, so the ordinary `body`
            block would otherwise never print and the payslip would show "No line
            items" where its figures belong.
        --}}
        @include($body)
    @elseif (! empty($receipt))
        {{--
            A payment receipt has one figure, not a table of lines. Without this
            branch a receipt in the SigmaShop design would print "No line items"
            where its amount belongs.
        --}}
        <div style="margin-top: 7mm;">
            @include('documents.blocks.canvas.receipt')
        </div>
    @elseif (! empty($sigmaTable['headers'] ?? []))
        <table class="sigma-items" style="width: 100%; table-layout: fixed; border-collapse: collapse; margin-top: 7mm;">
            <thead>
                <tr>
                    @foreach ($sigmaTable['headers'] as $index => $header)
                        <th style="width: {{ $sigmaTable['widths'][$index] ?? 'auto' }}; padding: 6px; text-align: {{ ($sigmaTable['aligns'][$index] ?? 'left') === 'right' ? 'right' : 'left' }}; border: 1px solid {{ $sigmaBrand }};">{{ $header }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($sigmaTable['rows'] ?? [] as $row)
                    <tr>
                        @foreach ((array) $row as $index => $cell)
                            <td style="padding: 6px; border-bottom: 1px solid #ddd; vertical-align: top; text-align: {{ ($sigmaTable['aligns'][$index] ?? 'left') === 'right' ? 'right' : 'left' }}; word-wrap: break-word; overflow-wrap: break-word;">{{ $cell }}</td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="{{ count($sigmaTable['headers']) }}" style="padding: 12px; text-align: center; color: #6b7280;">{{ $sigmaTable['empty'] ?? 'No records found.' }}</td></tr>
                @endforelse
            </tbody>
        </table>
    @else
        <table class="sigma-items" style="width: 100%; table-layout: fixed; border-collapse: collapse; margin-top: 7mm;">
            <thead>
                <tr>
                    <th style="width: 6%; padding: 6px; text-align: left; border: 1px solid {{ $sigmaBrand }};">#</th>
                    <th style="width: 42%; padding: 6px; text-align: left; border: 1px solid {{ $sigmaBrand }};">Description</th>
                    <th style="width: 12%; padding: 6px; text-align: right; border: 1px solid {{ $sigmaBrand }};">Qty</th>
                    <th style="width: 18%; padding: 6px; text-align: right; border: 1px solid {{ $sigmaBrand }};">Price</th>
                    <th style="width: 22%; padding: 6px; text-align: right; border: 1px solid {{ $sigmaBrand }};">Total</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows ?? [] as $index => $row)
                    <tr>
                        <td style="padding: 6px; border-bottom: 1px solid #ddd; vertical-align: top;">{{ $index + 1 }}</td>
                        <td class="sigma-desc" style="padding: 6px; border-bottom: 1px solid #ddd; vertical-align: top; word-wrap: break-word; overflow-wrap: break-word; word-break: break-all;">{{ $row['description'] ?? '-' }}</td>
                        <td style="padding: 6px; border-bottom: 1px solid #ddd; text-align: right; white-space: nowrap;">{{ number_format((float) ($row['qty'] ?? 0), 2) }}</td>
                        <td style="padding: 6px; border-bottom: 1px solid #ddd; text-align: right; white-space: nowrap;">{{ number_format((float) ($row['unit_price'] ?? 0), 2) }}</td>
                        <td style="padding: 6px; border-bottom: 1px solid #ddd; text-align: right; white-space: nowrap; font-weight: bold;">{{ number_format((float) ($row['total'] ?? 0), 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" style="padding: 12px; text-align: center; color: #6b7280;">No line items.</td></tr>
                @endforelse
            </tbody>
        </table>
    @endif

    {{-- Right-floated totals table. --}}
    <div class="sigma-totals-wrap" style="margin-top: 7mm;">
        <table class="sigma-totals" style="width: 60%; margin-left: auto; border-collapse: collapse;">
            @foreach ($totals ?? [] as $total)
                <tr>
                    <td class="label" style="padding: 4px 8px; text-align: left; font-size: 9px; color: #374151;">{{ $total['label'] }} :</td>
                    <td class="value" style="padding: 4px 8px; text-align: right; font-size: 9px; white-space: nowrap;">{{ number_format((float) $total['value'], 2) }}</td>
                </tr>
            @endforeach
        </table>
    </div>

    @if (filled($notes))
        <div class="sigma-notes" style="margin-top: 8mm; padding: 8px; border: 1px solid #e5e7eb; font-size: 9px;">
            <strong style="color: {{ $sigmaBrand }};">Notes</strong>
            <p style="margin: 4px 0 0;">{{ $notes }}</p>
        </div>
    @endif

    <div class="sigma-footer" style="margin-top: 10mm; text-align: center; font-size: 9px; color: #374151;">
        <strong>Thank you for choosing {{ $sigmaCompany }}!</strong>
        <div style="margin-top: 2px;">{{ $sigmaCompany }}</div>
    </div>
</div>
