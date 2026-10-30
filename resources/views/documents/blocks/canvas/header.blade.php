{{--
    The letterhead block, as the canvas prints it.

    Two things live here and are worth naming separately, because they are what
    the `header` block is actually FOR:

        the document's own identity  -- "QUOTE", the number, the customer's
                                       reference, and the date
        the headline figure         -- the total, or the item count on a document
                                       that carries no money at all

    The second is why this is not just a masthead. On a delivery note the item
    count stands in for the total, because "Total 0.00" reads as a broken document
    rather than one that simply has nothing to charge for.

    Every style renders it, differently, from the same payload -- which is why the
    switch is here rather than in the parent template: the frame is the frame's
    business, and a style that could not find its own header is a style nobody
    looked at.

    @include is safe inside a PDF template; Blade components (@x-) and layouts
    (@extends) are not, because DomPDF cannot resolve them.
--}}
@php($pdfLayoutStyle = $layout->style())

{{-- The document kind and number -- "QUOTE", "QUOTE #QUO-LAYOUT-0001" -- belong to
    the `title` block, which is consultable per placement rather than printed from
    its own canvas partial: the classic style keeps it inside its bordered box, the
    masthead designs carry it in their band and the rail prints it at the top of
    the canvas. The settings screen can switch the block off, so every placement
    gates on `$layout->has('title')` and drops out of the print altogether. --}}

@if ($pdfLayoutStyle === \App\Support\PdfLayout::STYLE_CLASSIC)
    @if ($layout->has('title') || filled($referenceNumber))
        <div class="classic-box">
            @if ($layout->has('title'))
                <div class="doc-kind">{{ strtoupper($title) }}</div>
                <div class="doc-number">{{ $title }} #{{ $number }}</div>
            @endif
            @if (filled($referenceNumber))
                <div class="doc-number">Your ref: {{ $referenceNumber }}</div>
            @endif
        </div>
    @endif
@elseif ($pdfLayoutStyle === \App\Support\PdfLayout::STYLE_MINIMAL)
    @if ($layout->has('title'))
        <div class="doc-kind" style="font-size: 15px; letter-spacing: 0.24em; font-weight: bold;">{{ strtoupper($title) }}</div>
        <div class="doc-number" style="font-size: 10px;"># {{ $number }}{{ filled($referenceNumber) ? '<br>Your ref: '.$referenceNumber : '' }}</div>
    @endif
@elseif ($pdfLayoutStyle !== \App\Support\PdfLayout::STYLE_RAIL)
    {{-- modern and banded carry the title in the band across the top --}}
@else
    @if ($layout->has('title'))
        <div class="doc-kind">{{ strtoupper($title) }}</div>
        <div class="doc-number">{{ $title }} #{{ $number }}</div>
    @endif
@endif

@if ($hasPricing)
    <div class="doc-date">Date: {{ $doc->issue_date?->format('d/m/Y') ?: '-' }}</div>
    <div class="doc-due-label">Total</div>
    <div class="doc-due">{{ money((float) ($doc->total ?: 0), $currency) }}</div>
@else
    <div class="doc-date">Date: {{ $doc->issue_date?->format('d/m/Y') ?: '-' }}</div>
    <div class="doc-due-label">Items</div>
    <div class="doc-due">{{ count($rows) }}</div>
@endif

@if (filled($referenceNumber) && ! in_array($pdfLayoutStyle, [\App\Support\PdfLayout::STYLE_CLASSIC, \App\Support\PdfLayout::STYLE_MINIMAL], true))
    <div class="doc-number">Your ref: {{ $referenceNumber }}</div>
@endif
