@props(['type', 'data', 'options', 'height' => '600', 'id' => null])

@php
    $chartId = $id ?? 'chart-'.substr(md5($data.$type.$height.uniqid('', true)), 0, 12);
    $chartHeight = max(1, (int) $height);
@endphp

{{-- The wrapper needs an explicit height: with maintainAspectRatio:false Chart.js
     sizes the canvas to its container, which collapses to 0 without one. --}}
<div style="height: {{ $chartHeight }}px;">
    <canvas id="{{ $chartId }}"></canvas>
</div>

<script>
    (function () {
        var render = function () {
            var el = document.getElementById(@json($chartId));
            if (!el || !window.Chart || el.dataset.cbRendered) return;
            el.dataset.cbRendered = '1';
            new window.Chart(el.getContext('2d'), {
                type: @json($type),
                data: {!! $data !!},
                options: {!! $options !!}
            });
        };

        window.__cbRenderCharts = window.__cbRenderCharts || [];
        window.__cbRenderCharts.push(render);

        if (window.Chart) {
            render();
        } else {
            document.addEventListener('DOMContentLoaded', render, { once: true });
        }
    })();
</script>
