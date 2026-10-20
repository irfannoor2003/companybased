{{--
    Dates and document details -- issue date, validity, expected delivery, status,
    currency, tracking. Built by `DocumentData::metaFor()`, which already decides
    which rows apply to which document type and hides the ones with nothing in them.
--}}
<div class="doc-meta">
    @foreach ($meta as $metaRow)
        @if ($metaRow['show'] && filled($metaRow['value']) && $metaRow['value'] !== '-')
            <div class="doc-meta-row">
                <span class="doc-meta-label">{{ $metaRow['label'] }}</span>
                <span class="doc-meta-value">{{ $metaRow['value'] }}</span>
            </div>
        @endif
    @endforeach
</div>
