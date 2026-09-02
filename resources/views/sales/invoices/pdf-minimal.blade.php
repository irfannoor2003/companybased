<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->number }} — {{ company_name() }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', Helvetica, Arial, sans-serif; color: #111827; font-size: 11px; margin: 0; padding: 0; }
        .page { padding: 44px 48px; }
        .header { border-bottom: 1px solid #d1d5db; padding-bottom: 28px; margin-bottom: 28px; display: flex; justify-content: space-between; align-items: flex-end; }
        .brand-name { font-size: 22px; font-weight: 300; letter-spacing: 0.08em; color: #111827; }
        .brand-line { font-size: 10px; color: #9ca3af; margin-top: 4px; }
        .ident { text-align: right; }
        .ident h1 { font-size: 26px; font-weight: 700; margin: 0; color: #111827; }
        .ident .num { font-size: 11px; color: #6b7280; margin-top: 2px; letter-spacing: 0.04em; }
        .cols { display: flex; justify-content: space-between; margin-bottom: 32px; }
        .block h3 { font-size: 10px; text-transform: uppercase; letter-spacing: 0.12em; color: #9ca3af; font-weight: 600; margin: 0 0 8px; }
        .block p { margin: 2px 0; color: #374151; }
        .block .ident { text-align: right; }
        table.data { width: 100%; border-collapse: collapse; font-size: 10px; }
        table.data thead th { text-align: left; font-weight: 600; color: #6b7280; font-size: 9px; text-transform: uppercase; letter-spacing: 0.08em; padding: 6px 8px; border-bottom: 1px solid #e5e7eb; }
        table.data tbody td { padding: 8px; border-bottom: 1px solid #f3f4f6; color: #111827; }
        .num { text-align: right; white-space: nowrap; }
        .summary { margin-top: 24px; margin-left: auto; width: 280px; }
        .summary table { width: 100%; border-collapse: collapse; }
        .summary td { padding: 5px 8px; }
        .summary td.label { text-align: right; color: #6b7280; }
        .summary td.value { text-align: right; font-weight: 700; }
        .summary tr.total { border-top: 2px solid #111827; }
        .summary tr.total td { font-size: 14px; padding-top: 8px; }
        .notes { margin-top: 28px; padding-top: 16px; border-top: 1px solid #e5e7eb; color: #6b7280; font-size: 10px; }
        .footer { margin-top: 32px; padding-top: 12px; border-top: 1px solid #f3f4f6; color: #c4c7cd; font-size: 9px; display: flex; justify-content: space-between; }
        @page { margin: 0; }
    </style>
</head>
<body>
    <div class="page">
        <div class="header">
            <div>
                @if (settings('branding.logo'))
                    <img src="{{ storage_path('app/public/'.settings('branding.logo')) }}" alt="{{ company_name() }}" style="max-height:52px;max-width:170px;display:block;margin-bottom:6px;">
                @endif
                <div class="brand-name">{{ company_name() }}</div>
                @if (settings('company.email'))
                    <div class="brand-line">{{ settings('company.email') }}</div>
                @endif
            </div>
            <div class="ident">
                <h1>Invoice</h1>
                <div class="num">#{{ $invoice->number }}</div>
            </div>
        </div>

        <div class="cols">
            <div class="block">
                <h3>From</h3>
                <p>{{ company_name() }}</p>
                @if (settings('company.address'))
                    <p>{{ settings('company.address') }}</p>
                @endif
                @if (settings('company.phone'))
                    <p>{{ settings('company.phone') }}</p>
                @endif
            </div>
            <div class="block">
                <h3>Dates</h3>
                <p>Issue: {{ $invoice->issue_date?->format('Y-m-d') }}</p>
                <p>Due: {{ $invoice->due_date?->format('Y-m-d') ?: '—' }}</p>
                <p>Status: {{ ucfirst(str_replace('_', ' ', $invoice->status)) }}</p>
            </div>
            <div class="block ident">
                <h3>Bill to</h3>
                <p>{{ $invoice->customer?->company_name ?: '—' }}</p>
                @if ($invoice->customer?->address)
                    <p>{{ $invoice->customer->address }}</p>
                @endif
                @if ($invoice->customer?->tax_number)
                    <p>Tax: {{ $invoice->customer->tax_number }}</p>
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

        @if ($invoice->notes)
            <div class="notes"><strong>Notes:</strong> {{ $invoice->notes }}</div>
        @endif

        <div class="footer">
            <span>{{ company_name() }}</span>
            <span>Generated {{ now()->format('Y-m-d H:i') }}</span>
        </div>
    </div>
</body>
</html>
