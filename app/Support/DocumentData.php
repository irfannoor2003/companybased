<?php

namespace App\Support;

use App\Models\DebitNote;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseQuote;
use App\Models\SalesCreditNote;
use App\Models\SalesDeliveryNote;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Models\SalesQuote;
use App\Models\SalesRecurringInvoice;
use Illuminate\Database\Eloquent\Model;

/**
 * Normalises any transactional document (quotes, orders, credit/debit notes,
 * delivery notes, purchase quotes/orders) into a single dataset consumed by the
 * shared show + PDF views. Keeps per-type differences in one place instead of
 * duplicating 14 views.
 */
class DocumentData
{
    /**
     * @var array<class-string, array{title: string, route: string, kind: string, pricing: bool, applied: bool, type: string, settlement?: bool}>
     */
    public const TYPES = [
        SalesQuote::class => ['title' => 'Quote', 'route' => 'sales.quotes', 'kind' => 'customer', 'pricing' => true, 'applied' => false, 'type' => 'quote'],
        SalesOrder::class => ['title' => 'Order', 'route' => 'sales.orders', 'kind' => 'customer', 'pricing' => true, 'applied' => false, 'type' => 'order'],

        /*
         * The sales invoice prints from this template too.
         *
         * It had its own design for years, which meant it also sat outside the
         * template system: a business could set "Modern Invoice" as its default and
         * watch its invoices come out exactly as before. Every other document type
         * honoured the template; the most-printed document in the system did not,
         * and the operator had no way to see that.
         *
         * The differences that mattered -- a due date, a salesperson, and the
         * amount still owed -- are data, not layout, so they belong in `metaFor()`
         * and `totalsFor()` below rather than in a second copy of the canvas.
         *
         * `applied` stays false: it adds Applied/Remaining rows sourced from
         * `remaining()`, which only credit and debit notes have. An invoice's
         * settlement comes from its own payments and credit.
         */
        SalesInvoice::class => ['title' => 'Invoice', 'route' => 'sales.invoices', 'kind' => 'customer', 'pricing' => true, 'applied' => false, 'type' => 'invoice', 'settlement' => true],

        SalesCreditNote::class => ['title' => 'Credit Note', 'route' => 'sales.credit_notes', 'kind' => 'customer', 'pricing' => true, 'applied' => true, 'type' => 'credit_note'],
        SalesDeliveryNote::class => ['title' => 'Delivery Note', 'route' => 'sales.delivery_notes', 'kind' => 'customer', 'pricing' => false, 'applied' => false, 'type' => 'delivery_note'],
        SalesRecurringInvoice::class => ['title' => 'Recurring Invoice', 'route' => 'sales.recurring_invoices', 'kind' => 'customer', 'pricing' => true, 'applied' => false, 'type' => 'recurring_invoice'],
        PurchaseQuote::class => ['title' => 'Purchase Quote', 'route' => 'suppliers.purchase_quotes', 'kind' => 'supplier', 'pricing' => true, 'applied' => false, 'type' => 'purchase_quote'],
        PurchaseOrder::class => ['title' => 'Purchase Order', 'route' => 'suppliers.purchase_orders', 'kind' => 'supplier', 'pricing' => true, 'applied' => false, 'type' => 'purchase_order'],
        DebitNote::class => ['title' => 'Debit Note', 'route' => 'suppliers.debit_notes', 'kind' => 'supplier', 'pricing' => true, 'applied' => true, 'type' => 'debit_note'],

        /*
         * A purchase invoice was missing here, which is why it was the one
         * `applied` stays false: that flag adds Applied/Remaining rows sourced
         * from a `remaining()` method, which only credit and debit notes have. A
         * bill's settlement figures come from its own payments, not from an
         * application, so calling remaining() on it threw.
         */
        PurchaseInvoice::class => ['title' => 'Bill', 'route' => 'suppliers.purchase_invoices', 'kind' => 'supplier', 'pricing' => true, 'applied' => false, 'type' => 'purchase_invoice'],
    ];

    /**
     * Build the normalized view dataset for a document model.
     *
     * @return array<string,mixed>
     */
    public static function build(Model $document): array
    {
        $def = self::TYPES[$document::class] ?? null;

        if ($def === null) {
            return [];
        }

        $partner = $def['kind'] === 'supplier' ? $document->supplier : $document->customer;
        $currency = self::currencyFor($document);

        return self::printable([
            'doc' => $document,
            'title' => $def['title'],
            'number' => $document->number,
            'referenceNumber' => $document->reference_number,
            'currency' => $currency,
            'meta' => self::metaFor($document, $def, $partner, $currency),
            'billTo' => self::billToFor($document, $def, $partner),
            'columns' => self::columnsFor($def),
            'rows' => self::rowsFor($document, $def),
            'totals' => self::totalsFor($document, $def),
            'notes' => $document->notes,
            'hasPricing' => $def['pricing'],
            'hasApplied' => $def['applied'],
            /**
             * Which template this document prints with.
             *
             * Passed as an OBJECT, so `printable()` leaves it alone -- it walks arrays
             * and strings, and the layout is neither. It is resolved from the default
             * template for the type, which is the setting the operator actually changes.
             */
            'layout' => PdfLayout::forDocumentType((string) $def['type']),
            'viewPermission' => $def['route'].'.view',
            'editRoute' => route($def['route'].'.edit', $document),
            'pdfRoute' => route($def['route'].'.pdf', $document),
            'backRoute' => route($def['route'].'.index'),
        ]);
    }

    /**
     * Punctuation the PDF engine cannot draw, mapped to something it can.
     *
     * DomPDF renders each of these as `???` on the printed page, because its default
     * font has no glyph for them. It is not a crash and not a layout fault -- the text
     * is simply wrong on an invoice the customer reads, which is worse than either.
     *
     *     "Impact Driver 20V — retail"   ->  "Impact Driver 20V - retail"
     *
     * Transliterated rather than deleted. An em-dash carrying meaning ("A – B") must
     * not simply vanish, and `-` reads correctly where `???` does not.
     */
    private const TRANSLITERATIONS = [
        // Dashes
        "\xE2\x80\x94" => '-',      // em dash —
        "\xE2\x80\x93" => '-',      // en dash –
        "\xE2\x80\x90" => '-',      // hyphen
        "\xE2\x88\x92" => '-',      // minus −
        "\xE2\x80\xA0" => ' ',      // narrow no-break space
        "\xC2\xA0" => ' ',          // no-break space
        // Quotes
        "\xE2\x80\x98" => "'",      // left single '
        "\xE2\x80\x99" => "'",      // right single '
        "\xE2\x80\x9C" => '"',      // left double "
        "\xE2\x80\x9D" => '"',      // right double "
        "\xE2\x80\x9E" => '"',      // double prime
        "\xE2\x80\xB2" => "'",      // prime
        // Marks and symbols
        "\xE2\x80\xA6" => '...',    // horizontal ellipsis
        "\xE2\x80\xA2" => '-',      // bullet
        "\xE2\x80\xA3" => '-',      // dagger
        "\xC2\xA9" => '(c)',        // copyright
        "\xC2\xAE" => '(r)',        // registered
        "\xE2\x84\xA2" => 'TM',     // trade mark
        "\xE2\x84\xA0" => '/do',    // per mille
        "\xC2\xB0" => ' deg',       // degree
        "\xC2\xB1" => '+/-',        // plus-minus
        "\xC2\xD7" => 'x',          // multiplication
        "\xE2\x89\x88" => '<=',     // less-equal
        "\xE2\x89\xA5" => '>=',     // greater-equal
        "\xE2\x88\x99" => '!=',     // not equal
        "\xE2\x86\x92" => '->',     // rightwards arrow
        "\xE2\x86\x90" => '<-',     // leftwards arrow
        "\xE2\x86\xB3" => '<=>',    // double arrow
        // Box drawing / blocks: the ones a pasted table or emoji can drag in
        "\xE2\x94\x80" => '-', "\xE2\x94\x81" => '|', "\xE2\x94\x82" => '|',
        "\xE2\x94\x8C" => '+', "\xE2\x94\x90" => '+', "\xE2\x94\x94" => '+',
        "\xE2\x96\x88" => '[', "\xE2\x96\x89" => ']', "\xE2\x96\x8A" => '[', "\xE2\x96\x8B" => ']',
    ];

    /**
     * Make every string in the payload safe to print.
     *
     * Applied to the WHOLE payload rather than to named fields, on purpose: a template
     * that adds a new text field is then covered automatically, instead of the bug
     * returning the next time someone prints a field nobody remembered.
     *
     * Applied at build() rather than only in the PDF views so the browser preview shows
     * exactly what will print. A preview that renders an em-dash while the PDF prints
     * `???` is a preview nobody can trust.
     *
     * Letters of any script are preserved. DomPDF's font coverage is a separate problem
     * from punctuation -- if a document carries Urdu or Greek text it needs a font that
     * has those glyphs, and silently deleting the text here would destroy the data to
     * hide a font misconfiguration.
     */
    public static function printable(array $payload): array
    {
        foreach ($payload as $key => $value) {
            // The model is handed to the template as-is. Walking it would walk every
            // relation and cast, and the template reads what it needs from it directly.
            if ($key === 'doc') {
                continue;
            }

            if (is_array($value)) {
                $payload[$key] = self::printable($value);

                continue;
            }

            if (is_string($value)) {
                $payload[$key] = self::printableString($value);
            }
        }

        return $payload;
    }

    public static function printableString(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        $text = strtr($text, self::TRANSLITERATIONS);

        // Anything still outside printable ASCII: keep it if it is a letter or a digit,
        // drop it if it is a stray symbol we have no sensible ASCII for. Leaving raw
        // bytes in place would print them as `???`.
        return preg_replace_callback(
            '/[^\x20-\x7E]/u',
            fn (array $m): string => preg_match('/\p{L}|\p{N}/u', $m[0]) === 1 ? $m[0] : '',
            $text,
        );
    }

    public static function currencyFor(Model $document): string
    {
        $currency = (string) ($document->currency ?: settings('company.currency') ?: 'USD');

        return $currency !== '' ? $currency : 'USD';
    }

    /**
     * @return array<int, array{label: string, value: string|null, show: bool}>
     */
    protected static function metaFor(Model $document, array $def, $partner, string $currency): array
    {
        $type = $def['type'];

        $dateLabel = $type === 'purchase_order' ? 'Order date' : 'Issue date';
        $dateField = $type === 'purchase_order' ? 'order_date' : 'issue_date';
        $dateValue = $document->{$dateField}?->format('Y-m-d') ?: '—';

        $rows = [];

        // The manual / external number is shown alongside the system number, not
        // instead of it: the business quotes the manual one to its customer while
        // the system one is the internal identity.
        if (filled($document->reference_number)) {
            $rows[] = ['label' => 'Manual number', 'value' => $document->reference_number, 'show' => true];
        }

        $rows[] = ['label' => $dateLabel, 'value' => $dateValue, 'show' => true];

        if (in_array($type, ['quote', 'purchase_quote'], true)) {
            $rows[] = ['label' => 'Valid until', 'value' => $document->valid_until?->format('Y-m-d') ?: '—', 'show' => true];
        }

        if (in_array($type, ['order', 'purchase_order'], true)) {
            $rows[] = ['label' => 'Expected delivery', 'value' => $document->expected_delivery_date?->format('Y-m-d') ?: '—', 'show' => true];
        }

        if (in_array($type, ['credit_note', 'debit_note'], true) && $document->reason) {
            $rows[] = ['label' => 'Reason', 'value' => $document->reason, 'show' => true];
        }

        $rows[] = ['label' => 'Status', 'value' => ucfirst(str_replace('_', ' ', (string) $document->status)), 'show' => true];

        /**
         * An invoice carries a due date, which nothing else does, and a salesperson.
         *
         * Both are the two facts a customer chases an invoice about. The due date is
         * not the document date with a number attached to it -- it is when the money
         * is wanted -- and the salesperson is who to ask when it is late.
         *
         * Read off the document rather than derived: the sales order the invoice came
         * from may have a promised date that has since moved, and the printed invoice
         * has to agree with what `SalesInvoice::due_date` says the ledger does.
         */
        if ($type === 'invoice') {
            if ($document->due_date) {
                $rows[] = ['label' => 'Due date', 'value' => $document->due_date->format('Y-m-d'), 'show' => true];
            }

            /**
             * The rep on the order, not on the invoice.
             *
             * `sales_invoices` has no `salesman_id` -- `sales_orders` and
             * `sales_quotes` do, and an invoice is raised against an order, so the
             * rep is already recorded one link up. Reading it from there rather than
             * copying it into a second column means there is one answer to "who sold
             * this", not two that can drift.
             *
             * An invoice raised without an order has no rep to name, and the row is
             * omitted rather than printed empty.
             */
            $salesperson = $document->order?->salesman?->name;

            if (filled($salesperson)) {
                $rows[] = ['label' => 'Salesperson', 'value' => $salesperson, 'show' => true];
            }
        }

        if ($def['pricing']) {
            $rows[] = ['label' => 'Currency', 'value' => $document->currency ?: $currency ?: '—', 'show' => true];
        }

        if (in_array($type, ['delivery_note', 'purchase_order'], true) && $document->shipping_address) {
            $rows[] = ['label' => 'Shipping address', 'value' => $document->shipping_address, 'show' => true];
        }

        if ($type === 'delivery_note') {
            if ($document->carrier) {
                $rows[] = ['label' => 'Carrier', 'value' => $document->carrier, 'show' => true];
            }
            if ($document->tracking_number) {
                $rows[] = ['label' => 'Tracking number', 'value' => $document->tracking_number, 'show' => true];
            }
        }

        if (in_array($type, ['credit_note', 'debit_note'], true) && $document->invoice?->number) {
            $rows[] = ['label' => 'Invoice', 'value' => $document->invoice->number, 'show' => true];
        }

        return $rows;
    }

    /**
     * @return array<int, array{label: string, value: mixed, total: bool}>
     */
    protected static function totalsFor(Model $document, array $def): array
    {
        if (! $def['pricing']) {
            return [];
        }

        // Priced with DocumentItems::lineTotals(), the same per-line arithmetic the
        // create and edit screens use. Summing raw qty * unit_price in float was a
        // second, independent implementation of the same rule, and the two drifted
        // by a cent on long documents -- which showed up as a printed gross that did
        // not reconcile with the printed discount and subtotal.
        $gross = 0.0;

        foreach ($document->items as $item) {
            $gross += DocumentItems::lineTotals(
                (float) $item->qty,
                (float) $item->unit_price,
                (float) ($item->discount_percent ?? 0),
                (float) ($item->tax_percent ?? 0),
                (float) ($item->discount_amount ?? 0),
            )['line_subtotal'];
        }

        $gross = round($gross, 2);

        // A document with no items summed to a gross of 0.00 and printed
        // "Subtotal 0.00" directly above a non-zero "Total", which reads as a
        // broken document rather than an empty one. Fall back to the stored
        // subtotal, which is the figure the total was actually built from.
        $gross = $gross > 0.0 ? $gross : (float) ($document->subtotal ?: 0);

        $discount = round($gross - (float) ($document->subtotal ?: 0), 2);

        $totals = [
            ['label' => 'Subtotal', 'value' => $gross, 'total' => false],
            ['label' => 'Tax', 'value' => (float) ($document->tax_amount ?: 0), 'total' => false],
            ['label' => 'Total', 'value' => (float) ($document->total ?: 0), 'total' => true],
        ];

        if ($discount > 0) {
            array_splice($totals, 1, 0, [['label' => 'Discount', 'value' => -$discount, 'total' => false]]);
        }

        if ($def['applied']) {
            $totals[] = ['label' => 'Applied', 'value' => (float) ($document->applied_amount ?: 0), 'total' => false];
            $totals[] = ['label' => 'Remaining', 'value' => (float) $document->remaining(), 'total' => false];
        }

        /**
         * An invoice is the only document that is ASKED for money, so it is the only
         * one that states what is still owed -- and it has to read as a statement
         * rather than as an error.
         *
         * "Amount due" while a balance remains; "Paid in full" once there is none. A
         * document that has been settled reads differently from one awaiting money,
         * and printing the same "Amount due 0.00" on both would tell the customer to
         * pay for something they have already paid.
         *
         * The balance is the model's own `balance()` -- cash, forward credit and
         * credit notes -- rather than a subtraction written here, because three places
         * computing what is owed differently is how the dashboard and the invoice
         * started disagreeing.
         */
        if ($def['settlement'] ?? false) {
            $balance = round($document->balance(), 2);

            if ($balance > 0.004) {
                $totals[] = ['label' => 'Amount due', 'value' => $balance, 'total' => false];
            } elseif ($balance < -0.004) {
                $totals[] = ['label' => 'Overpaid', 'value' => abs($balance), 'total' => false];
            } elseif ($document->settledAmount() > 0) {
                $totals[] = ['label' => 'Paid in full', 'value' => $document->total, 'total' => false];
            }
        }

        return $totals;
    }

    /**
     * @return array<int, array{label: string, align: string}>
     */
    protected static function columnsFor(array $def): array
    {
        if ($def['pricing']) {
            return [
                ['label' => '#', 'align' => 'center'],
                ['label' => 'Description', 'align' => 'left'],
                ['label' => 'Qty', 'align' => 'right'],
                ['label' => 'Unit price', 'align' => 'right'],
                ['label' => 'Disc.', 'align' => 'right'],
                ['label' => 'Tax', 'align' => 'right'],
                ['label' => 'Amount', 'align' => 'right'],
            ];
        }

        return [
            ['label' => '#', 'align' => 'center'],
            ['label' => 'Description', 'align' => 'left'],
            ['label' => 'Qty', 'align' => 'right'],
        ];
    }

    /**
     * @return array<int, array<string,float|string>>
     */
    protected static function rowsFor(Model $document, array $def): array
    {
        $rows = [];

        foreach ($document->items as $item) {
            if ($def['pricing']) {
                $line = DocumentItems::lineTotals(
                    (float) $item->qty,
                    (float) $item->unit_price,
                    (float) ($item->discount_percent ?? 0),
                    (float) ($item->tax_percent ?? 0),
                    (float) ($item->discount_amount ?? 0),
                );

                $rows[] = [
                    'description' => $item->description ?: ($item->product?->name ?? '—'),
                    'qty' => (float) $item->qty,
                    'unit_price' => (float) $item->unit_price,
                    'discount' => $line['line_discount'],
                    'tax' => $line['line_tax'],
                    'total' => round($line['line_net'] + $line['line_tax'], 2),
                ];
            } else {
                $rows[] = [
                    'description' => $item->description ?: ($item->product?->name ?? '—'),
                    'qty' => (float) $item->qty,
                ];
            }
        }

        return $rows;
    }

    /**
     * @return array{heading: string, name: string|null, contact: string|null, address: string|null, tax: string|null, code: string|null}
     */
    protected static function billToFor(Model $document, array $def, $partner): array
    {
        return [
            'heading' => $def['kind'] === 'supplier' ? 'Supplier' : 'Bill to',
            'name' => $partner?->company_name ?: null,
            'contact' => $partner?->contact_name ?: null,
            'address' => $partner?->address ?: null,
            'tax' => $partner?->tax_number ?: null,
            'code' => $partner?->short_code ?: null,
        ];
    }
}
