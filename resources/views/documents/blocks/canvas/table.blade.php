{{--
    The generic data table.

    Statements, list exports and ledger reports are documents too: they have a
    letterhead, a title, meta rows and totals, and a table of rows. Printing them
    through this block means they inherit the operator's template -- style,
    colours, header and footer -- instead of carrying a second design that drifts
    away from the invoice. The items block stays for documents that sell products;
    this one is for everything else that is fundamentally a list.
--}}
@if (! empty($table) && ! empty($table['headers']))
    <table class="data-table">
        <thead>
            <tr>
                @foreach ($table['headers'] as $index => $header)
                    <th class="{{ ($table['aligns'][$index] ?? 'left') === 'right' ? 'num' : '' }}" @if (isset($table['widths'][$index])) style="width: {{ $table['widths'][$index] }};" @endif>{{ $header }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($table['rows'] ?? [] as $rowIndex => $row)
                <tr @if (($rowIndex % 2) === 1) class="alt" @endif>
                    @foreach ((array) $row as $index => $cell)
                        <td class="{{ ($table['aligns'][$index] ?? 'left') === 'right' ? 'num' : '' }}">{{ $cell }}</td>
                    @endforeach
                </tr>
            @empty
                <tr class="empty"><td colspan="{{ count($table['headers']) }}">{{ $table['empty'] ?? 'No records found.' }}</td></tr>
            @endforelse
        </tbody>
    </table>
@endif
