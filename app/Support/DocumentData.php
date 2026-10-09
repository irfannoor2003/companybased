<?php

namespace App\Support;

use App\Models\DebitNote;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseQuote;
use App\Models\SalesCreditNote;
use App\Models\SalesDeliveryNote;
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
     * @var array<class-string, array{title: string, route: string, kind: string, pricing: bool, applied: bool, type: string}>
     */
    public const TYPES = [
        SalesQuote::class => ['title' => 'Quote', 'route' => 'sales.quotes', 'kind' => 'customer', 'pricing' => true, 'applied' => false, 'type' => 'quote'],
        SalesOrder::class => ['title' => 'Order', 'route' => 'sales.orders', 'kind' => 'customer', 'pricing' => true, 'applied' => false, 'type' => 'order'],
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

        return [
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
            'viewPermission' => $def['route'].'.view',
            'editRoute' => route($def['route'].'.edit', $document),
            'pdfRoute' => route($def['route'].'.pdf', $document),
            'backRoute' => route($def['route'].'.index'),
        ];
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
