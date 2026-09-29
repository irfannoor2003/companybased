@php
    // Branded from the Super Admin's Settings → Company Profile configuration.
    $brand = $brand ?? \App\Support\Branding::payload();
    $logo = $brand['logo'] ?? null;
    $logoUrl = $logo ? \Illuminate\Support\Facades\Storage::disk('public')->url($logo) : null;

    // Substitute the brand colours into the stylesheet. Kept in a separate .css
    // file so the CSS braces are never parsed as Blade syntax. Note: __DIR__
    // would point at the compiled view cache, not the source directory.
    $theme = str_replace(
        ['__PRIMARY__', '__STRONG__'],
        ['rgb('.$brand['primaryRgb'].')', 'rgb('.$brand['primaryStrongRgb'].')'],
        file_get_contents(resource_path('views/vendor/mail/html/theme.css'))
    );
@endphp
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<title>{{ $brand['companyName'] }}</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<style>{!! $theme !!}</style>
{!! $head ?? '' !!}
</head>
<body>

<table class="wrapper" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="center">
<table class="content" width="100%" cellpadding="0" cellspacing="0" role="presentation">
{{-- Branded header: uploaded logo when present, otherwise the company name --}}
<tr>
<td class="header-cell">
@if ($logoUrl)
    <a href="{{ url('/') }}" style="text-decoration:none;">
        <img class="header-logo" src="{{ $logoUrl }}" alt="{{ $brand['companyName'] }}" />
    </a>
@else
    <a href="{{ url('/') }}">{{ $brand['companyName'] }}</a>
@endif
</td>
</tr>

<!-- Email Body -->
<tr>
<td class="body" width="100%" cellpadding="0" cellspacing="0" style="border: hidden !important;">
<table class="inner-body" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation">
<!-- Body content -->
<tr>
<td class="content-cell" style="padding: 32px 24px;">
{!! Illuminate\Mail\Markdown::parse($slot) !!}

{!! $subcopy ?? '' !!}
</td>
</tr>
</table>
</td>
</tr>

{!! $footer ?? '' !!}
</table>
</td>
</tr>
</table>
</body>
</html>
