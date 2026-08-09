<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }} — {{ company_name() }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', Helvetica, Arial, sans-serif;
            color: #1f2937;
            font-size: 11px;
            margin: 0;
            padding: 0;
        }
        .page { padding: 32px 40px; }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 3px solid {{ settings('branding.primary_color', '#4f46e5') }};
            padding-bottom: 16px;
            margin-bottom: 20px;
        }
        .brand { display: flex; align-items: center; gap: 12px; }
        .brand-name {
            font-size: 20px;
            font-weight: 700;
            color: {{ settings('branding.primary_color', '#4f46e5') }};
            letter-spacing: -0.02em;
        }
        .brand-tagline { color: #6b7280; font-size: 10px; margin-top: 2px; }
        .doc-title { text-align: right; }
        .doc-title h1 {
            font-size: 22px;
            font-weight: 700;
            margin: 0;
            color: #111827;
        }
        .doc-title p { margin: 2px 0 0; color: #6b7280; font-size: 10px; }

        .meta {
            display: table;
            width: 100%;
            margin-bottom: 18px;
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            padding: 10px 14px;
        }
        .meta-row { display: table-row; }
        .meta-row dt {
            display: table-cell;
            width: 130px;
            font-weight: 600;
            color: #374151;
            padding: 3px 0;
            text-transform: uppercase;
            font-size: 9px;
            letter-spacing: 0.04em;
        }
        .meta-row dd { display: table-cell; color: #111827; padding: 3px 0; }

        table.data {
            width: 100%;
            border-collapse: collapse;
            font-size: 10px;
        }
        table.data thead th {
            background: {{ settings('branding.primary_color', '#4f46e5') }};
            color: #ffffff;
            text-align: left;
            padding: 7px 8px;
            font-weight: 600;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        table.data tbody td {
            padding: 6px 8px;
            border-bottom: 1px solid #e5e7eb;
            color: #1f2937;
        }
        table.data tbody tr:nth-child(even) { background: #f9fafb; }
        table.data tbody tr:last-child td { border-bottom: none; }
        .num { text-align: right; white-space: nowrap; }

        .footer {
            margin-top: 24px;
            padding-top: 12px;
            border-top: 1px solid #e5e7eb;
            color: #9ca3af;
            font-size: 9px;
            display: flex;
            justify-content: space-between;
        }
        .notes {
            margin-top: 20px;
            padding: 10px 14px;
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 6px;
            color: #92400e;
            font-size: 10px;
        }
        .watermark {
            position: fixed;
            top: 45%;
            left: 0;
            right: 0;
            text-align: center;
            color: {{ settings('branding.primary_color', '#4f46e5') }};
            opacity: 0.05;
            font-size: 64px;
            font-weight: 800;
            transform: rotate(-20deg);
        }
        @page { margin: 0; }
    </style>
</head>
<body>
    @isset($watermark)
        <div class="watermark">{{ $watermark }}</div>
    @endisset

    <div class="page">
        <div class="header">
            <div class="brand">
                @if (settings('branding.logo'))
                    <img src="{{ storage_path('app/public/'.settings('branding.logo')) }}" style="max-height:40px;max-width:140px;">
                @else
                    <div class="brand-name">{{ company_name() }}</div>
                @endif
            </div>
            <div class="doc-title">
                <h1>{{ $title }}</h1>
                @isset($subtitle)
                    <p>{{ $subtitle }}</p>
                @endisset
            </div>
        </div>

        @if (! empty($meta))
            <dl class="meta">
                @foreach ($meta as $key => $value)
                    <div class="meta-row">
                        <dt>{{ $key }}</dt>
                        <dd>{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif

        @if (! empty($rows))
            <table class="data">
                <thead>
                    <tr>
                        @foreach ($headers as $header)
                            <th>{{ $header }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            @foreach ($row as $cell)
                                <td class="{{ is_numeric(str_replace([',', ' ', '$'], '', (string) $cell)) ? 'num' : '' }}">{{ $cell }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p style="color:#6b7280;">No records found.</p>
        @endif

        @isset($notes)
            <div class="notes">{{ $notes }}</div>
        @endisset

        <div class="footer">
            <span>{{ company_name() }} · {{ settings('company.email') ?? '' }}</span>
            <span>Generated {{ now()->format('Y-m-d H:i') }}</span>
        </div>
    </div>
</body>
</html>
