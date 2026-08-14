<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->number }} — {{ company_name() }}</title>
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
        .meta { display: table; width: 100%; margin-bottom: 18px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 10px 14px; }
        .meta-row { display: table-row; }
        .meta-row dt { display: table-cell; width: 130px; font-weight: 600; color: #374151; padding: 3px 0; text-transform: uppercase; font-size: 9px; letter-spacing: 0.04em; }
        .meta-row dd { display: table-cell; color: #111827; padding: 3px 0; }
        table.data { width: 100%; border-collapse: collapse; font-size: 10px; }
        table.data thead th { background: {{ settings('branding.primary_color', '#4f46e5') }}; color: #ffffff; text-align: left; padding: 7px 8px; font-weight: 600; font-size: 9px; text-transform: uppercase; letter-spacing: 0.04em; }
        table.data tbody td { padding: 6px 8px; border-bottom: 1px solid #e5e7eb; color: #1f2937; }
        table.data tbody tr:nth-child(even) { background: #f9fafb; }
        table.data tbody tr:last-child td { border-bottom: none; }
        .num { text-align: right; white-space: nowrap; }
        .summary { margin-top: 18px; }
        .summary table { width: 100%; max-width: 280px; margin-left: auto; border-collapse: collapse; }
        .summary td { padding: 4px 8px; border-bottom: 1px solid #e5e7eb; }
        .summary td.label { text-align: right; color: #6b7280; font-weight: 500; }
        .summary td.value { text-align: right; font-weight: 700; }
        .summary tr.total td { border-top: 2px solid #d1d5db; }
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
                <h1>Invoice</h1>
                <p>#{{ $invoice->number }}</p>
            </div>
        </div>

        <dl class="meta">
            <div class="meta-row"><dt>Issue date</dt><dd>{{ $invoice->issue_date?->format('Y-m-d') }}</dd></div>
            <div class="meta-row"><dt>Due date</dt><dd>{{ $invoice->due_date?->format('Y-m-d') ?: '—' }}</dd></div>
            <div class="meta-row"><dt>Status</dt><dd>{{ ucfirst(str_replace('_', ' ', $invoice->status)) }}</dd></div>
            <div class="meta-row"><dt>Currency</dt><dd>{{ $invoice->currency ?: '—' }}</dd></div>
            <div class="meta-row"><dt>Customer</dt><dd>{{ $invoice->customer?->company_name ?: '—' }}</dd></div>
            @if ($invoice->customer?->short_code)
                <div class="meta-row"><dt>Customer code</dt><dd>{{ $invoice->customer->short_code }}</dd></div>
            @endif
            @if ($invoice->customer?->tax_number)
                <div class="meta-row"><dt>Tax number</dt><dd>{{ $invoice->customer->tax_number }}</dd></div>
            @endif
        </dl>

        <table class="data">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Description</th>
                    <th class="num">Qty</th>
                    <th class="num">Unit price</th>
                    <th class="num">Tax</th>
                    <th class="num">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($invoice->items as $item)
                    @php
                        $lineNet = (float) $item->qty * (float) $item->unit_price * (1 - (float) $item->discount_percent / 100);
                        $lineTax = $lineNet * ((float) $item->tax_percent / 100);
                        $lineTotal = $lineNet + $lineTax;
                    @endphp
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td>{{ $item->description ?: ($item->product?->name ?? '—') }}</td>
                        <td class="num">{{ number_format((float) $item->qty, 2) }}</td>
                        <td class="num">{{ number_format((float) $item->unit_price, 2) }}</td>
                        <td class="num">{{ number_format((float) $lineTax, 2) }}</td>
                        <td class="num">{{ number_format((float) $lineTotal, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="summary">
            <table>
                <tr><td class="label">Subtotal</td><td class="value">{{ number_format((float) $invoice->subtotal, 2) }}</td></tr>
                <tr><td class="label">Tax</td><td class="value">{{ number_format((float) $invoice->tax_amount, 2) }}</td></tr>
                <tr class="total"><td class="label">Total</td><td class="value">{{ number_format((float) $invoice->total, 2) }}</td></tr>
                <tr><td class="label">Paid</td><td class="value">{{ number_format((float) $invoice->paid_amount, 2) }}</td></tr>
                <tr><td class="label">Balance</td><td class="value">{{ number_format((float) $invoice->balance(), 2) }}</td></tr>
            </table>
        </div>

        @if ($invoice->notes)
            <div class="meta" style="margin-top: 18px;">
                <div class="meta-row"><dt>Notes</dt><dd>{{ $invoice->notes }}</dd></div>
            </div>
        @endif

        <div class="footer">
            <span>{{ company_name() }}</span>
            <span>Generated {{ now()->format('Y-m-d H:i') }}</span>
        </div>
    </div>
</body>
</html>
