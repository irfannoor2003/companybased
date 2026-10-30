@include('documents.partials.frame')

    {{--
        The canvas itself is this document's own: the bill-to letter, the line
        items, the totals, the notes and the signature.

        This loop is what makes the block order the operator chose actually mean
        something. The template used to print a fixed sequence and consult
        `has()` only to decide whether to skip a piece, so moving the totals
        above the line items changed nothing at all.

        `canvasBlocks()` rather than `blocks()`, because in the rail design the
        bill-to, bank details and payment terms are printed down the coloured
        band instead -- they are handled above, in the template's order too, so
        reordering reaches them as well.
    --}}
    @foreach ($layout->canvasBlocks() as $pdfBlock)
        {{--
            `title` has no canvas partial of its own: depending on the style it
            is printed inside the classic box, the masthead band, the minimal
            topline or the rail's opening lines, and each of those gates on
            `$layout->has('title')`. Including it here would look for
            `documents.blocks.canvas.title` and die, so it is skipped in the
            flow deliberately.
        --}}
        @continue($pdfBlock === 'title')
        @continue(! ($blockReady[$pdfBlock] ?? true))
        @include('documents.blocks.canvas.'.$pdfBlock)
    @endforeach

    @if ($layout->footerHtml() !== null)
        {{--
                The operator's custom footer, printed AFTER the built-in one so both
                are on the page: the built-in footer is the company identity, the
                custom one is whatever the business itself wants to say last.
            --}}
        <div class="custom-html custom-html-footer">{!! $layout->footerHtml() !!}</div>
    @endif
</div>
</body>
</html>
