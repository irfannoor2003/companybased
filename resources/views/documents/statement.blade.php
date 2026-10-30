@include('documents.partials.frame')

    {{--
        The statement body, printed through the SAME frame the invoice prints
        through, because the company asked for its account statement to be "in
        the form of the company invoice" -- same template choice, same brand
        colours, same rail/masthead, same table treatment.

        What changes is the canvas: instead of line items and a single total, a
        statement prints the period's meta, the running ledger and the closing
        balance. The class names (items / totals / grand) are the invoice's own,
        so the template's style transformations -- banded zebra rows, minimal
        hairlines, brand-coloured grand total -- apply here without a second
        copy of any CSS.

        Consumed by BOTH statements: the customer account statement
        (StatementController::pdf) and the supplier ledger statement
        (SupplierLedgerController::pdf), so the two cannot drift.

        @include is safe inside a PDF template; Blade components (@x-) and
        layouts (@extends) are not, because DomPDF cannot resolve them.
    --}}
    @php($pdfLayoutStyle = $layout->style())

    {{-- The title block, mirroring documents.blocks.canvas.header per style, but
         with a statement's identity: no due date and no "Your ref", and the
         headline figure is the closing balance rather than an invoice total. --}}
    @if ($pdfLayoutStyle === \App\Support\PdfLayout::STYLE_CLASSIC)
        @if ($layout->has('title'))
            <div class="classic-box">
                <div class="doc-kind">{{ strtoupper($title) }}</div>
                <div class="doc-number"># {{ $number }}</div>
            </div>
        @endif
    @elseif ($pdfLayoutStyle === \App\Support\PdfLayout::STYLE_MINIMAL)
        @if ($layout->has('title'))
            <div class="doc-kind" style="font-size: 15px; letter-spacing: 0.24em; font-weight: bold;">{{ strtoupper($title) }}</div>
            <div class="doc-number" style="font-size: 10px;"># {{ $number }}</div>
        @endif
    @elseif ($pdfLayoutStyle !== \App\Support\PdfLayout::STYLE_RAIL)
        {{-- modern and banded carry the title in the band across the top --}}
    @else
        @if ($layout->has('title'))
            <div class="doc-kind">{{ strtoupper($title) }}</div>
            <div class="doc-number"># {{ $number }}</div>
        @endif
    @endif

    <div class="doc-date">Statement date: {{ $asOf->format('d/m/Y') }}</div>
    <div class="doc-due-label">Closing balance</div>
    <div class="doc-due">{{ money((float) $totals['closing'], $currency) }}</div>

    {{-- The period the statement stands for, in the meta rows the invoice
         template prints for issue dates and payment terms. --}}
    <div class="doc-meta">
        <div class="doc-meta-row"><span class="doc-meta-label">Coverage</span><span class="doc-meta-value">All account activity to date</span></div>
        <div class="doc-meta-row"><span class="doc-meta-label">Account currency</span><span class="doc-meta-value">{{ $currency }}</span></div>
        <div class="doc-meta-row"><span class="doc-meta-label">Opening balance</span><span class="doc-meta-value">{{ money((float) $totals['opening'], $currency) }}</span></div>
        <div class="doc-meta-row"><span class="doc-meta-label">Total debited</span><span class="doc-meta-value">{{ money((float) $totals['debited'], $currency) }}</span></div>
        <div class="doc-meta-row"><span class="doc-meta-label">Total credited</span><span class="doc-meta-value">{{ money((float) $totals['credited'], $currency) }}</span></div>
    </div>

    @include('documents.blocks.canvas.party')

    {{-- The running ledger. The Debit column is money the account was charged
         (invoices a customer was issued, payments and debit notes on a supplier
         ledger); Credit is money that reduced it. The Balance column carries the
         running figure after each row, exactly as the on-screen statement does --
         both read the same rows from the same controller method. --}}
    <table class="items">
        <thead>
            <tr>
                <th style="width: 11%">Date</th>
                <th style="width: 13%">Type</th>
                <th style="width: 33%">Reference</th>
                <th class="num" style="width: 14%">Debit</th>
                <th class="num" style="width: 14%">Credit</th>
                <th class="num" style="width: 15%">Balance</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($ledger as $entry)
                <tr @if ($pdfLayoutStyle === \App\Support\PdfLayout::STYLE_BANDED && $loop->index % 2 === 1) class="alt" @endif>
                    <td class="idx" style="width: 11%">{{ $entry['date']?->format('Y-m-d') ?: '-' }}</td>
                    <td style="width: 13%">{{ $entry['type'] }}</td>
                    <td>{{ $entry['reference'] }}</td>
                    <td class="num">{{ $entry['debit'] !== null ? money((float) $entry['debit'], $currency) : '' }}</td>
                    <td class="num">{{ $entry['credit'] !== null ? money((float) $entry['credit'], $currency) : '' }}</td>
                    <td class="num total">{{ money((float) $entry['balance'], $currency) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" style="padding: 9px; text-align: center; color: #79839a;">No account activity on record.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    {{-- Totals in the invoice's own treatment -- outlined grand row on classic,
         brand-filled on banded, rules on ink for minimal. --}}
    <table class="totals-wrap" style="margin-top: 9mm;">
        <tr>
            <td class="gutter"></td>
            <td>
                <table class="totals">
                    <tr>
                        <td class="label">Total debited</td>
                        <td class="value">{{ money((float) $totals['debited'], $currency) }}</td>
                    </tr>
                    <tr>
                        <td class="label">Total credited</td>
                        <td class="value">{{ money((float) $totals['credited'], $currency) }}</td>
                    </tr>
                    <tr class="grand">
                        <td class="label">Closing balance</td>
                        <td class="value">{{ money((float) $totals['closing'], $currency) }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    @if (filled($notes))
        <div class="notes"><p>{{ $notes }}</p></div>
    @endif

    @if ($layout->footerHtml() !== null)
        {{-- The operator's custom footer, after everything else the statement prints. --}}
        <div class="custom-html custom-html-footer">{!! $layout->footerHtml() !!}</div>
    @endif
</div>
</body>
</html>
