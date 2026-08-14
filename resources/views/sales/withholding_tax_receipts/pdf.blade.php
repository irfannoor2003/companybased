<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Withholding tax receipt {{ $withholdingTaxReceipt->number }} — {{ company_name() }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', Helvetica, Arial, sans-serif; color: #1f2937; font-size: 11px; margin: 0; padding: 0; }
        .page { padding: 32px 40px; }
        .header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid {{ settings('branding.primary_color', '#4f46e5') }}; padding-bottom: 16px; margin-bottom: 20px; }
        .brand-name { font-size: 20px; font-weight: 700; color: {{ settings('branding.primary_color', '#4f46e5') }}; }
        .brand-tagline { color: #6b7280; font-size: 10px; margin-top: 2px; }
        .doc-title { text-align: right; }
        .doc-title h1 { font-size: 22px; font-weight: 700; margin: 0; color: #111827; }
        .doc-title p { margin: 2px 0 0; color: #6b7280; font-size: 10px; }
        .meta { width: 100%; margin-bottom: 18px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 10px 14px; }
        .meta-row { display: table; width: 100%; }
        .meta-row dt { display: table-cell; width: 130px; font-weight: 600; color: #374151; padding: 3px 0; text-transform: uppercase; font-size: 9px; letter-spacing: 0.04em; }
        .meta-row dd { display: table-cell; color: #111827; padding: 3px 0; }
        .amount-box { display: flex; justify-content: space-between; align-items: center; border: 1px solid #e5e7eb; border-radius: 8px; padding: 14px 18px; margin-bottom: 16px; }
        .amount-box .label { color: #6b7280; font-size: 10px; text-transform: uppercase; letter-spacing: 0.04em; }
        .amount-box .value { font-size: 20px; font-weight: 700; color: {{ settings('branding.primary_color', '#4f46e5') }}; }
        .footer { margin-top: 24px; padding-top: 12px; border-top: 1px solid #e5e7eb; color: #9ca3af; font-size: 9px; display: flex; justify-content: space-between; }
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
                <h1>Withholding Tax Receipt</h1>
                <p>#{{ $withholdingTaxReceipt->number }}</p>
            </div>
        </div>

        <dl class="meta">
            <div class="meta-row"><dt>Customer</dt><dd>{{ $withholdingTaxReceipt->customer?->company_name ?: '—' }}</dd></div>
            <div class="meta-row"><dt>Receipt date</dt><dd>{{ $withholdingTaxReceipt->receipt_date?->format('Y-m-d') }}</dd></div>
            <div class="meta-row"><dt>Against invoice</dt><dd>{{ $withholdingTaxReceipt->invoice?->number ?: '—' }}</dd></div>
            <div class="meta-row"><dt>Currency</dt><dd>{{ $withholdingTaxReceipt->currency ?: '—' }}</dd></div>
        </dl>

        <div class="amount-box">
            <div>
                <div class="label">Payment amount</div>
                <div class="value">{{ money($withholdingTaxReceipt->amount, $withholdingTaxReceipt->currency) }}</div>
            </div>
        </div>

        <dl class="meta">
            <div class="meta-row"><dt>Withholding rate</dt><dd>{{ $withholdingTaxReceipt->tax_rate_percent }}%</dd></div>
            <div class="meta-row"><dt>Tax withheld</dt><dd>{{ money($withholdingTaxReceipt->tax_amount, $withholdingTaxReceipt->currency) }}</dd></div>
        </dl>

        @if ($withholdingTaxReceipt->notes)
            <dl class="meta">
                <div class="meta-row"><dt>Notes</dt><dd>{{ $withholdingTaxReceipt->notes }}</dd></div>
            </dl>
        @endif

        <div class="footer">
            <span>{{ company_name() }}</span>
            <span>Generated {{ now()->format('Y-m-d H:i') }}</span>
        </div>
    </div>
</body>
</html>