<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Purchase Invoice {{ $invoice->number }} — {{ company_name() }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', Helvetica, Arial, sans-serif; color: #1f2937; font-size: 11px; margin: 0; padding: 0; }
        .page { padding: 0; }
        .banner { background: {{ settings('branding.primary_color', '#4f46e5') }}; color: #ffffff; padding: 24px 40px; display: flex; justify-content: space-between; align-items: center; }
        .banner .brand-name { font-size: 20px; font-weight: 700; }
        .banner .brand-tagline { font-size: 10px; opacity: 0.85; margin-top: 3px; }
        .banner .doc-label { text-align: right; }
        .banner .doc-label h1 { font-size: 22px; font-weight: 700; margin: 0; }
        .banner .doc-label p { margin: 2px 0 0; font-size: 10px; opacity: 0.85; }
        .body { padding: 28px 40px 32px; }
        .logo { max-height: 48px; max-width: 160px; display: block; margin-bottom: 6px; }
        .info { display: flex; justify-content: space-between; margin-bottom: 24px; }
        .info h3 { font-size: 10px; text-transform: uppercase; letter-spacing: 0.06em; color: {{ settings('branding.primary_color', '#4f46e5') }}; margin: 0 0 6px; }
        .info p { margin: 2px 0; color: #4b5563; }
        .info .ident { text-align: right; }
        table.data { width: 100%; border-collapse: collapse; font-size: 10px; }
        table.data thead th { text-align: left; color: {{ settings('branding.primary_color', '#4f46e5') }}; border-bottom: 2px solid {{ settings('branding.primary_color', '#4f46e5') }}; padding: 7px 8px; font-weight: 700; font-size: 9px; text-transform: uppercase; letter-spacing: 0.04em; }
        table.data tbody td { padding: 7px 8px; border-bottom: 1px solid #e5e7eb; color: #1f2937; }
        table.data tbody tr:nth-child(even) { background: #f9fafb; }
        .num { text-align: right; white-space: nowrap; }
        .rows { display: flex; justify-content: space-between; align-items: flex-start; margin-top: 22px; }
        .summary { width: 300px; }
        .summary table { width: 100%; border-collapse: collapse; }
        .summary td { padding: 5px 10px; }
        .summary td.label { text-align: right; color: #6b7280; }
        .summary td.value { text-align: right; font-weight: 700; }
        .summary tr.total td { background: {{ settings('branding.primary_color', '#4f46e5') }}; color: #ffffff; border-radius: 4px; font-size: 13px; }
        .footer { margin-top: 26px; padding-top: 12px; border-top: 1px solid #e5e7eb; color: #9ca3af; font-size: 9px; display: flex; justify-content: space-between; }
        @page { margin: 0; }
    </style>
</head>
<body>
    <div class="page">
        <div class="banner">
            <div>
                @if (settings('branding.logo'))
                    <img src="{{ storage_path('app/public/'.settings('branding.logo')) }}" alt="{{ company_name() }}" class="logo">
                @endif
                <div class="brand-name">{{ company_name() }}</div>
                @if (settings('company.email'))
                    <div class="brand-tagline">{{ settings('company.email') }}</div>
                @endif
            </div>
            <div class="doc-label">
                <h1>Purchase Invoice</h1>
                <p>#{{ $invoice->number }}</p>
            </div>
        </div>

        <div class="body">
            <div class="info">
                <div>
                    <h3>From</h3>
                    <p>{{ company_name() }}</p>
                    @if (settings('company.address'))
                        <p>{{ settings('company.address') }}</p>
                    @endif
                    @if (settings('company.phone'))
                        <p>{{ settings('company.phone') }}</p>
                    @endif
                </div>
                <div class="ident">
                    <h3>Bill from</h3>
                    <p>{{ $invoice->supplier?->company_name ?: '—' }}</p>
                    @if ($invoice->supplier?->address)
                        <p>{{ $invoice->supplier->address }}</p>
                    @endif
                    @if ($invoice->supplier?->tax_number)
                        <p>Tax: {{ $invoice->supplier->tax_number }}</p>
                    @endif
                    <p style="margin-top:6px;">Issue: {{ $invoice->issue_date?->format('Y-m-d') }}</p>
                    <p>Due: {{ $invoice->due_date?->format('Y-m-d') ?: '—' }}</p>
                    <p>Status: {{ ucfirst(str_replace('_', ' ', $invoice->status)) }}</p>
                    @if ($invoice->productionOrder)
                        <p>Production order: {{ $invoice->productionOrder->number }}</p>
                    @endif
                </div>
            </div>

            <table class="data">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Description</th>
                        <th class="num">Qty</th>
                        <th class="num">Unit price</th>
                        <th class="num">Disc.</th>
                        <th class="num">Tax</th>
                        <th class="num">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($invoice->items as $item)
                        @php
                            $lineGross = (float) $item->qty * (float) $item->unit_price;
                            $lineNet = $lineGross * (1 - (float) $item->discount_percent / 100);
                            $lineTax = $lineNet * ((float) $item->tax_percent / 100);
                            $lineTotal = $lineNet + $lineTax;
                            $lineDiscount = $lineGross - $lineNet;
                        @endphp
                        <tr>
                            <td>{{ $loop->iteration }}</td>
                            <td>{{ $item->description ?: ($item->product?->name ?? '—') }}</td>
                            <td class="num">{{ number_format((float) $item->qty, 2) }}</td>
                            <td class="num">{{ number_format((float) $item->unit_price, 2) }}</td>
                            <td class="num">{{ $lineDiscount > 0 ? number_format($lineDiscount, 2) : '—' }}</td>
                            <td class="num">{{ number_format((float) $lineTax, 2) }}</td>
                            <td class="num">{{ number_format((float) $lineTotal, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            @php
                $docGross = $invoice->items->sum(fn ($it) => (float) $it->qty * (float) $it->unit_price);
                $docDiscount = $docGross - (float) $invoice->subtotal;
            @endphp
            <div class="rows">
                <div style="font-size:10px;color:#6b7280;max-width:320px;">
                    @if ($invoice->notes)
                        <p><strong>Notes:</strong> {{ $invoice->notes }}</p>
                    @endif
                    @if ($invoice->currency)
                        <p>Currency: {{ $invoice->currency }}</p>
                    @endif
                </div>
                <div class="summary">
                    <table>
                        <tr><td class="label">Subtotal</td><td class="value">{{ number_format($docGross, 2) }}</td></tr>
                        @if ($docDiscount > 0)
                            <tr><td class="label">Discount</td><td class="value">-{{ number_format($docDiscount, 2) }}</td></tr>
                        @endif
                        <tr><td class="label">Tax</td><td class="value">{{ number_format((float) $invoice->tax_amount, 2) }}</td></tr>
                        <tr><td class="label">Paid</td><td class="value">{{ number_format((float) $invoice->paid_amount, 2) }}</td></tr>
                        <tr class="total"><td class="label">Total</td><td class="value">{{ number_format((float) $invoice->total, 2) }}</td></tr>
                    </table>
                </div>
            </div>

            <div class="footer">
                <span>{{ company_name() }}</span>
                <span>Generated {{ now()->format('Y-m-d H:i') }}</span>
            </div>
        </div>
    </div>
</body>
</html>
