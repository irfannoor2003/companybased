<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $docTitle }} {{ $receipt->number }} - {{ company_name() }}</title>

    {{--
        ONE receipt design for every money-received / money-paid document.

        Three separate templates used to exist -- sales payments, supplier payments
        and withholding tax receipts -- and each was a near-identical copy that had
        drifted. They are now this single template, fed by an adapter that presents
        any of the three as the same shape.

        A standalone document for DomPDF: plain inline CSS only, no Blade layout, no
        components, no Tailwind, no CSS custom properties (DomPDF does not resolve
        them). Column widths are percentages because DomPDF ignores millimetres on
        <td>.
    --}}
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', Helvetica, Arial, sans-serif; color: #1f2937; font-size: 11px; margin: 0; padding: 0; }
        .page { padding: 32px 40px; }
        .header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid {{ $brandColor }}; padding-bottom: 16px; margin-bottom: 20px; }
        .brand-name { font-size: 20px; font-weight: 700; color: {{ $brandColor }}; }
        .brand-tagline { color: #6b7280; font-size: 10px; margin-top: 2px; }
        .doc-title { text-align: right; }
        .doc-title h1 { font-size: 22px; font-weight: 700; margin: 0; color: #111827; }
        .doc-title p { margin: 2px 0 0; color: #6b7280; font-size: 10px; }
        .meta { width: 100%; margin-bottom: 18px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 10px 14px; }
        .meta-row { display: table; width: 100%; }
        .meta-row dt { display: table-cell; width: 130px; font-weight: 600; color: #374151; padding: 3px 0; text-transform: uppercase; font-size: 9px; letter-spacing: 0.04em; }
        .meta-row dd { display: table-cell; padding: 3px 0; color: #111827; }
        .amount-box { display: table; width: 100%; background: {{ $brandTint }}; border: 1px solid {{ $brandColor }}; border-radius: 6px; padding: 14px 18px; margin-bottom: 18px; }
        .amount-box .label { font-size: 9px; text-transform: uppercase; letter-spacing: 0.05em; color: #6b7280; margin-bottom: 4px; }
        .amount-box .value { font-size: 20px; font-weight: 700; color: #111827; }
        .footer { display: table; width: 100%; border-top: 1px solid #e5e7eb; padding-top: 10px; margin-top: 24px; color: #9ca3af; font-size: 9px; }
        .footer span:first-child { float: left; }
        .footer span:last-child { float: right; }
        @page { margin: 0; }
    </style>
</head>
<body>
    <div class="page">
        <div class="header">
            <div>
                <div class="brand-name">{{ company_name() }}</div>
                @if (settings('company.email'))
                    <div class="brand-tagline">{{ settings('company.email') }}</div>
                @endif
            </div>
            <div class="doc-title">
                <h1>{{ $docTitle }}</h1>
                <p>#{{ $receipt->number }}</p>
            </div>
        </div>

        <dl class="meta">
            <div class="meta-row"><dt>{{ $partyLabel }}</dt><dd>{{ $partyName ?: '—' }}</dd></div>
            @if ($partyCode)
                <div class="meta-row"><dt>{{ $partyCodeLabel }}</dt><dd>{{ $partyCode }}</dd></div>
            @endif
            <div class="meta-row"><dt>{{ $dateLabel }}</dt><dd>{{ $receiptDate?->format('Y-m-d') ?: '—' }}</dd></div>
            @if ($method)
                <div class="meta-row"><dt>Method</dt><dd>{{ ucfirst(str_replace('_', ' ', $method)) }}</dd></div>
            @endif
            @if ($receipt->reference)
                <div class="meta-row"><dt>Reference</dt><dd>{{ $receipt->reference }}</dd></div>
            @endif
            @if ($againstLabel && $againstNumber)
                <div class="meta-row"><dt>{{ $againstLabel }}</dt><dd>{{ $againstNumber }}</dd></div>
            @endif
            <div class="meta-row"><dt>Currency</dt><dd>{{ $receipt->currency ?: '—' }}</dd></div>
        </dl>

        <div class="amount-box">
            <div>
                <div class="label">{{ $amountLabel }}</div>
                <div class="value">{{ money($receipt->amount, $receipt->currency) }}</div>
            </div>
        </div>

        @if ($receipt->notes)
            <dl class="meta">
                <div class="meta-row"><dt>Notes</dt><dd>{{ $receipt->notes }}</dd></div>
            </dl>
        @endif

        <div class="footer">
            <span>{{ company_name() }}</span>
            <span>Generated {{ now()->format('Y-m-d H:i') }}</span>
        </div>
    </div>
</body>
</html>