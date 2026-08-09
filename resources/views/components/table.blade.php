@props([
    'striped' => false,
    'hover' => true,
    'empty' => null,
    'emptyTitle' => 'No records found',
    'emptyDescription' => 'Try adjusting your search or filters.',
    'emptyIcon' => 'inbox',
])

<div class="table-wrap">
    <table {{ $attributes->merge(['class' => 'table-base']) }}>
        @isset($thead)
            <thead>{{ $thead }}</thead>
        @endisset

        <tbody>
            {{ $slot }}

            @if (($empty ?? false) || ($slot->isEmpty() ?? false))
                <tr>
                    <td colspan="{{ $colspan ?? 99 }}">
                        <x-empty-state
                            :title="$emptyTitle"
                            :description="$emptyDescription"
                            :icon="$emptyIcon"
                        />
                    </td>
                </tr>
            @endif
        </tbody>

        @isset($tfoot)
            <tfoot>{{ $tfoot }}</tfoot>
        @endisset
    </table>
</div>
