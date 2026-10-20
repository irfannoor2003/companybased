{{--
    The totals block.

    Skipped outright on a document that carries no money -- a delivery note -- rather
    than printing "Subtotal 0.00" above a table of quantities, which reads as a
    broken document rather than an honest one.

    The block is sized as a percentage of the canvas rather than a fixed 74mm plus a
    50mm gutter. Those two summed to 124mm, wider than the canvas column once the
    rail and the canvas's own left padding were taken off it, and the right-hand
    value column ran off the paper: "180.00" printed as "180.0".
--}}
@if ($hasPricing)
    <div class="settlement">
        <table class="totals-wrap">
            <tr>
                <td class="gutter"></td>
                <td>
                    <table class="totals">
                        @foreach ($totals as $totalRow)
                            <tr @if ($totalRow['total']) class="grand" @endif>
                                <td class="label">{{ $totalRow['label'] }}</td>
                                <td class="value">
                                    @if ($totalRow['total'] && $totalRow['value'] < 0)
                                        -{{ number_format(abs((float) $totalRow['value']), 2) }}
                                    @else
                                        {{ number_format((float) $totalRow['value'], 2) }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </table>
                </td>
            </tr>
        </table>
    </div>
@endif
