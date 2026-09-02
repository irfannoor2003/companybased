<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->number }} — {{ company_name() }}</title>
    @php
        $primary = settings('branding.primary_color', '#4f46e5');
    @endphp
    <style>
* { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', Helvetica, Arial, sans-serif; color: #1e293b; font-size: 11px; margin: 0; padding: 0; }
        .band { height: 5px; background: {{ $primary }}; }
        .head { background: #0f172a; color: #ffffff; padding: 26px 40px 22px; }
        .head-brand { font-size: 20px; font-weight: 700; }
        .head-tagline { font-size: 9.5px; color: #94a3b8; margin-top: 4px; line-height: 1.5; }
        .head-logo { max-height: 52px; max-width: 170px; display: block; margin-bottom: 8px; }
        .doc-label { text-align: right; }
        .doc-label .kind { font-size: 10px; letter-spacing: 0.22em; text-transform: uppercase; color: #c7d2fe; }
        .doc-label h1 { font-size: 26px; font-weight: 700; margin: 2px 0 4px; }
        .doc-label .number { font-size: 12px; font-weight: 700; color: #c7d2fe; }
        .doc-label .status { display: inline-block; margin-top: 8px; padding: 3px 10px; border-radius: 10px; font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; background: {{ $primary }}; color: #ffffff; }
        .body { padding: 26px 40px 30px; }
        .parties { width: 100%; border-collapse: collapse; margin-bottom: 22px; }
        .parties td { vertical-align: top; padding: 0; }
        .parties .divider { width: 28px; }
        .block-label { font-size: 9.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.14em; color: {{ $primary }}; margin-bottom: 6px; border-bottom: 2px solid {{ $primary }}; padding-bottom: 4px; }
        .block p { margin: 2px 0; color: #475569; line-height: 1.5; }
        .block .name { font-weight: 700; color: #0f172a; }
        .meta-row { margin-top: 7px; }
        .meta-row .k { display: inline-block; width: 92px; color: #64748b; font-size: 9.5px; text-transform: uppercase; letter-spacing: 0.08em; }
        table.data { width: 100%; border-collapse: collapse; font-size: 10px; }
        table.data thead th { text-align: left; color: #0f172a; border-bottom: 2px solid {{ $primary }}; background: #f8fafc; padding: 8px; font-weight: 700; font-size: 9px; text-transform: uppercase; letter-spacing: 0.04em; }
        table.data tbody td { padding: 7px 8px; border-bottom: 1px solid #e2e8f0; color: #1e293b; }
        table.data tbody tr:nth-child(even) td { background: #f8fafc; }
        .num { text-align: right; white-space: nowrap; }
        .bottom { width: 100%; border-collapse: collapse; margin-top: 22px; }
        .bottom > tr > td { vertical-align: top; }
        .notes-box { padding-right: 24px; }
        .notes-box p { margin: 4px 0 0; color: #475569; font-size: 10px; line-height: 1.5; }
        .summary { width: 310px; border: 1px solid #e2e8f0; border-top: 3px solid {{ $primary }}; }
        .summary table { width: 100%; border-collapse: collapse; }
        .summary td { padding: 6px 12px; border-bottom: 1px solid #f1f5f9; }
        .summary td.label { text-align: left; color: #64748b; }
        .summary td.value { text-align: right; font-weight: 700; color: #0f172a; }
        .summary tr.total td { background: {{ $primary }}; color: #ffffff; border-bottom: none; font-size: 13px; padding: 9px 12px; }
        .summary tr.total td.label { color: #e0e7ff; font-weight: 600; }
        .summary tr.total td.value { color: #ffffff; font-weight: 800; }
        .foot { margin-top: 26px; padding-top: 12px; border-top: 1px solid #e2e8f0; color: #94a3b8; font-size: 9px; text-align: center; line-height: 1.6; }
        @page { margin: 0; }
    </style>
</head>
<body>
    <div class="page">
        <div class="band"></div>
        <div class="head">
            <table width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
                <td valign="middle">
                    @if (settings('branding.logo'))
                        <img src="{{ storage_path('app/public/'.settings('branding.logo')) }}" alt="{{ company_name() }}" class="head-logo">
                    @else
                        <div class="head-brand">{{ company_name() }}</div>
                    @endif
                    @if (settings('company.address'))
                        <div class="head-tagline">{{ settings('company.address') }}</div>
                    @endif
                    @if (settings('company.email'))
                        <div class="head-tagline">{{ settings('company.email') }}</div>
                    @endif
                    @if (settings('company.phone'))
                        <div class="head-tagline">{{ settings('company.phone') }}</div>
                    @endif
                </td>
                <td width="220" class="doc-label">
                    <div class="kind">Invoice</div>
                    <h1>{{ $invoice->number }}</h1>
                    <div class="number">TAX INVOICE</div>
                    <div class="status">{{ ucfirst(str_replace('_', ' ', $invoice->status)) }}</div>
                </td>
            </tr></table>
        </div>

        <div class="body">
            <table class="parties" cellpadding="0" cellspacing="0" border="0">
                <tr>
                    <td style="width:48%">
                        <div class="block">
                            <div class="block-label">Bill to</div>
                            <p class="name">{{ $invoice->customer?->company_name ?: '—' }}</p>
                            @if ($invoice->customer?->contact_name)
                                <p>{{ $invoice->customer->contact_name }}</p>
                            @endif
                            @if ($invoice->customer?->address)
                                <p>{{ $invoice->customer->address }}</p>
                            @endif
                            @if ($invoice->customer?->tax_number)
                                <p>Tax: {{ $invoice->customer->tax_number }}</p>
                            @endif
                            @if ($invoice->customer?->short_code)
                                <p>Code: {{ $invoice->customer->short_code }}</p>
                            @endif
                        </div>
                    </td>
                    <td class="divider"></td>
                    <td style="width:48%">
                        <div class="meta-row"><span class="k">Issue date</span><span style="color:#0f172a;font-weight:600;">{{ $invoice->issue_date?->format('Y-m-d') }}</span></div>
                        <div class="meta-row"><span class="k">Due date</span><span style="color:#0f172a;font-weight:600;">{{ $invoice->due_date?->format('Y-m-d') ?: '—' }}</span></div>
                        <div class="meta-row"><span class="k">Currency</span><span style="color:#0f172a;font-weight:600;">{{ $invoice->currency ?: '—' }}</span></div>
                        @if ($invoice->order)
                            <div class="meta-row"><span class="k">Order</span><span style="color:#0f172a;font-weight:600;">{{ $invoice->order->number }}</span></div>
                        @endif
                    </td>
                </tr>
            </table>

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
                            <td class="num">{{ money($item->unit_price, $invoice->currency) }}</td>
                            <td class="num">{{ $lineDiscount > 0 ? '-'.money($lineDiscount, $invoice->currency) : '—' }}</td>
                            <td class="num">{{ money($lineTax, $invoice->currency) }}</td>
                            <td class="num">{{ money($lineTotal, $invoice->currency) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            @php
                $docGross = $invoice->items->sum(fn ($it) => (float) $it->qty * (float) $it->unit_price);
                $docDiscount = $docGross - (float) $invoice->subtotal;
            @endphp
            <table class="bottom" cellpadding="0" cellspacing="0" border="0">
                <tr>
                    <td class="notes-box">
                        @if ($invoice->notes)
                            <div class="block-label" style="border-color:#e2e8f0;color:#64748b;">Notes</div>
                            <p>{{ $invoice->notes }}</p>
                        @endif
                    </td>
                    <td>
                        <div class="summary">
                            <table cellpadding="0" cellspacing="0" border="0">
                                <tr><td class="label">Subtotal</td><td class="value">{{ money($docGross, $invoice->currency) }}</td></tr>
                                @if ($docDiscount > 0)
                                    <tr><td class="label">Discount</td><td class="value">-{{ money($docDiscount, $invoice->currency) }}</td></tr>
                                @endif
                                <tr><td class="label">Tax</td><td class="value">{{ money($invoice->tax_amount, $invoice->currency) }}</td></tr>
                                <tr><td class="label">Paid</td><td class="value">{{ money($invoice->paid_amount, $invoice->currency) }}</td></tr>
                                <tr class="total"><td class="label">Total</td><td class="value">{{ money($invoice->total, $invoice->currency) }}</td></tr>
                            </table>
                        </div>
                    </td>
                </tr>
            </table>

            <div class="foot">
                <span>Thank you for your business.</span><br>
                <span>{{ company_name() }} · Generated {{ now()->format('Y-m-d H:i') }}</span>
            </div>
        </div>
    </div>
</body>
</html>