{{--
    The footer: the address, phone, email, website and tax number.

    The last thing on the page and the quietest, on purpose. It repeats what the
    letterhead already said so that a page torn off the middle of a document still
    carries enough to identify who issued it.
--}}
<table class="footer">
    <tr>
        <td>{!! nl2br(e(settings('company.address', ''))) !!}</td>
        <td>
            {{-- Spelled out, not abbreviated.

                 This was "P:" and "M:" here until the invoice moved onto this
                 template. The invoice had a written brief against terse contact
                 abbreviations -- a customer-facing document should not read like a
                 packing slip -- and moving it onto a shared design that abbreviated
                 them would have quietly thrown that away.

                 Spelled out is also simply better on every other document. There is
                 no argument for the shorter form beyond the width of the column, and
                 the column fits. --}}
            @if (filled(settings('company.phone')))Phone: {{ settings('company.phone') }}<br>@endif
            @if (filled(settings('company.email')))Email: {{ settings('company.email') }}@endif
        </td>
        <td>
            @if (filled(settings('company.website')))Web: {{ settings('company.website') }}<br>@endif
            @if (filled(settings('company.tax_number')))Tax no. {{ settings('company.tax_number') }}@endif
        </td>
    </tr>
</table>
