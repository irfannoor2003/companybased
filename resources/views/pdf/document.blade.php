<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }} {{ $number }} — {{ company_name() }}</title>
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
        .billto { margin-bottom: 18px; }
        .billto h3 { font-size: 10px; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280; margin: 0 0 4px; }
        .billto p { margin: 1px 0; color: #111827; }
        table.data { width: 100%; border-collapse: collapse; font-size: 10px; }
        table.data thead th { background: {{ settings('branding.primary_color', '#4f46e5') }}; color: #ffffff; text-align: left; padding: 7px 8px; font-weight: 600; font-size: 9px; text-transform: uppercase; letter-spacing: 0.04em; }
        table.data tbody td { padding: 6px 8px; border-bottom: 1px solid #e5e7eb; color: #1f2937; }
        table.data tbody tr:nth-child(even) { background: #f9fafb; }
        table.data tbody tr:last-child td { border-bottom: none; }
        .num { text-align: right; white-space: nowrap; }
        .center { text-align: center; }
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
                @if (settings('branding.logo'))
                    <img src="{{ storage_path('app/public/'.settings('branding.logo')) }}" alt="{{ company_name() }}" style="max-height:64px;max-width:180px;margin-bottom:4px;">
                @endif
                <div class="brand-name">{{ company_name() }}</div>
                @if (settings('company.email'))
                    <div class="brand-tagline">{{ settings('company.email') }}</div>
                @endif
            </div>
            <div class="doc-title">
                <h1>{{ $title }}</h1>
                <p>#{{ $number }}</p>
            </div>
        </div>

        @if (!empty($meta))
            <dl class="meta">
                @foreach ($meta as $row)
                    @if ($row['show'] ?? true)
                        <div class="meta-row"><dt>{{ $row['label'] }}</dt><dd>{{ $row['value'] ?: '—' }}</dd></div>
                    @endif
                @endforeach
            </dl>
        @endif

        @if ($billTo['name'])
            <div class="billto">
                <h3>{{ $billTo['heading'] }}</h3>
                <p><strong>{{ $billTo['name'] }}</strong></p>
                @if ($billTo['contact'])<p>{{ $billTo['contact'] }}</p>@endif
                @if ($billTo['address'])<p>{{ $billTo['address'] }}</p>@endif
                @if ($billTo['tax'])<p>Tax: {{ $billTo['tax'] }}</p>@endif
                @if ($billTo['code'])<p>Code: {{ $billTo['code'] }}</p>@endif
            </div>
        @endif

        @if (!empty($columns))
            <table class="data">
                <thead>
                    <tr>
                        @foreach ($columns as $column)
                            <th class="{{ $column['align'] === 'right' ? 'num' : ($column['align'] === 'center' ? 'center' : '') }}">{{ $column['label'] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                        @if ($hasPricing)
                            <td>{{ $loop->iteration }}</td>
                            <td>{{ $row['description'] }}</td>
                            <td class="num">{{ number_format((float) $row['qty'], 2) }}</td>
                            <td class="num">{{ number_format((float) $row['unit_price'], 2) }}</td>
                            <td class="num">{{ ($row['discount'] ?? 0) > 0 ? number_format($row['discount'], 2) : '—' }}</td>
                            <td class="num">{{ number_format((float) $row['tax'], 2) }}</td>
                            <td class="num">{{ number_format((float) $row['total'], 2) }}</td>
                        @else
                                <td>{{ $loop->iteration }}</td>
                                <td>{{ $row['description'] }}</td>
                                <td class="num">{{ number_format((float) $row['qty'], 2) }}</td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        @if (!empty($totals))
            <div class="summary">
                <table>
                    @foreach ($totals as $total)
                        <tr class="{{ $total['total'] ? 'total' : '' }}">
                            <td class="label">{{ $total['label'] }}</td>
                            <td class="value">{{ number_format((float) $total['value'], 2) }}</td>
                        </tr>
                    @endforeach
                </table>
            </div>
        @endif

        @if ($notes)
            <div class="meta" style="margin-top: 18px;">
                <div class="meta-row"><dt>Notes</dt><dd>{{ $notes }}</dd></div>
            </div>
        @endif

        <div class="footer">
            <span>{{ company_name() }}</span>
            <span>Generated {{ now()->format('Y-m-d H:i') }}</span>
        </div>
    </div>
</body>
</html>
