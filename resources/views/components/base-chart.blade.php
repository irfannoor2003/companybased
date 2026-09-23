@props(['type', 'data', 'options', 'height' => '600', 'id' => null])

@php
    $chartId = $id ?? 'chart-'.md5($data.$type);
@endphp

<div>
    <canvas
        id="{{ $chartId }}"
        :height="height"
        class="w-full"
    ></canvas>

    <script>
        new Chart(
            document.getElementById('{{ $chartId }}'),
            {
                type: '{{ $type }}',
                data: {{ $data }},
                options: {{ $options }}
            }
        );
    </script>
</div>
