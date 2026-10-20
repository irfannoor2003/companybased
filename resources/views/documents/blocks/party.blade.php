{{--
    The bill-to / supplier block.

    Included twice in the rail design -- once in the left rail, once in the canvas --
    and once in the canvas for every other style, so it lives here rather than being
    written out twice and drifting.

    @include is safe inside a PDF template; Blade components (@x-) and layouts
    (@extends) are not, because DomPDF cannot resolve them.
--}}
<div class="rail-label">{{ $billTo['heading'] ?? 'Bill to' }}</div>
<div class="rail-name-strong">{{ $billTo['name'] ?: '-' }}</div>
@if (filled($billTo['contact']))
    <div class="rail-line">Attn: {{ $billTo['contact'] }}</div>
@endif
@if (filled($billTo['address']))
    <div class="rail-line">{!! nl2br(e($billTo['address'])) !!}</div>
@endif
@if (filled($billTo['code']))
    <div class="rail-line">Code: {{ $billTo['code'] }}</div>
@endif
@if (filled($billTo['tax']))
    <div class="rail-line">Tax no: {{ $billTo['tax'] }}</div>
@endif