<?php

namespace App\Support;

use App\Models\DebitNote;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseQuote;
use App\Models\SalesCreditNote;
use App\Models\SalesDeliveryNote;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceItem;
use App\Models\SalesOrder;
use App\Models\SalesPayment;
use App\Models\SalesQuote;
use App\Models\SalesRecurringInvoice;
use App\Models\SalesCustomer;
use App\Models\Supplier;
use App\Models\WithholdingTaxReceipt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Finds a document to render when an operator previews a template.
 *
 * A template editor whose Preview button 404s on a fresh installation is worse
 * than one with no preview at all: the operator has just designed something and
 * cannot see it. A new database has no invoices, quotes or payments, so looking
 * up a real document of the template's type cannot be the only path.
 *
 * So this class first uses the newest real document (which shows the real data
 * the operator will print), and when there is none builds an UNSAVED sample with
 * representative values and in-memory relations. Nothing is written to the
 * database, and the sample is rendered through exactly the same DocumentData /
 * documents.pdf pipeline as a real document, so the preview is honest about the
 * design: same blocks, same style, same colours.
 */
class TemplateSample
{
    /** @var array<string,class-string<Model>> */
    private const MODELS = [
        'quote' => SalesQuote::class,
        'order' => SalesOrder::class,
        'invoice' => SalesInvoice::class,
        'credit_note' => SalesCreditNote::class,
        'delivery_note' => SalesDeliveryNote::class,
        'recurring_invoice' => SalesRecurringInvoice::class,
        'purchase_quote' => PurchaseQuote::class,
        'purchase_order' => PurchaseOrder::class,
        'purchase_invoice' => PurchaseInvoice::class,
        'debit_note' => DebitNote::class,
        'receipt' => SalesPayment::class,
        'withholding_tax_receipt' => WithholdingTaxReceipt::class,
    ];

    public static function forType(string $type): Model
    {
        $class = self::MODELS[$type] ?? SalesInvoice::class;

        $real = $class::query()->latest('id')->first();

        if ($real !== null) {
            self::loadRelations($real, $type);

            return $real;
        }

        return self::synthetic($class, $type);
    }

    /**
     * Eager-load only what DocumentData reads for this type, so the preview does
     * not issue a query per relation from the Blade.
     */
    private static function loadRelations(Model $document, string $type): void
    {
        $relations = ['items.product'];

        if (in_array($type, ['credit_note', 'debit_note'], true)) {
            $relations[] = 'invoice';
        }

        if ($type === 'invoice') {
            $relations[] = 'order.salesman';
        }

        if (in_array($type, ['receipt', 'withholding_tax_receipt'], true)) {
            // A receipt has a customer OR a supplier depending on the document,
            // and not every receipt model has a bank account. Ask only for the
            // relations this model actually defines so loadMissing cannot throw.
            $relations = ['customer', 'supplier', 'invoice', 'bankAccount'];
        }

        $defined = array_values(array_filter(
            $relations,
            fn (string $relation) => method_exists($document, explode('.', $relation)[0]),
        ));

        $document->loadMissing($defined);
    }

    /**
     * Build an unsaved document of the right class with enough shape for
     * DocumentData::build() to produce a complete preview.
     */
    private static function synthetic(string $class, string $type): Model
    {
        $supplierKind = in_array($type, ['purchase_quote', 'purchase_order', 'purchase_invoice', 'debit_note'], true);

        $partner = $supplierKind
            ? new Supplier(['company_name' => 'Sample Supplier', 'contact_name' => 'Accounts Desk', 'address' => '12 Sample Street', 'tax_number' => 'TAX-0001', 'short_code' => 'SUP-1'])
            : new SalesCustomer(['company_name' => 'Sample Customer', 'contact_name' => 'Purchase Desk', 'address' => '12 Sample Street', 'tax_number' => 'TAX-0001', 'short_code' => 'CUS-1']);

        $date = Carbon::parse('2026-01-15');
        $due = Carbon::parse('2026-01-29');

        $attributes = [
            // route() needs a route key to generate Edit/PDF links in
            // DocumentData::build(). This model is never saved, so a placeholder
            // key is all that is needed and it cannot collide with real data.
            'id' => 1,
            'number' => 'PREVIEW-0001',
            'reference_number' => 'REF-0001',
            'issue_date' => $date,
            'order_date' => $date,
            'due_date' => $due,
            'valid_until' => $due,
            'expected_delivery_date' => $due,
            'receipt_date' => $date,
            'payment_date' => $date,
            'status' => 'sent',
            'currency' => settings('company.currency') ?: 'USD',
            'subtotal' => '500.00',
            'tax_amount' => '0.00',
            'total' => '500.00',
            'paid_amount' => '0.00',
            'credit_applied' => '0.00',
            'credit_note_applied' => '0.00',
            'applied_amount' => '0.00',
            'amount' => '500.00',
            'method' => 'bank_transfer',
            'reference' => 'BANK-REF-1',
            'notes' => 'Sample content shown for layout preview only.',
            'reason' => 'Sample adjustment',
        ];

        /** @var Model $document */
        $document = new $class;
        $document->forceFill($attributes);

        $item = new SalesInvoiceItem([
            'description' => 'Sample product or service',
            'qty' => 2,
            'unit_price' => 250,
            'discount_percent' => 0,
            'discount_amount' => 0,
            'tax_percent' => 0,
        ]);

        $document->setRelation('items', collect([$item]));
        $document->setRelation($supplierKind ? 'supplier' : 'customer', $partner);

        if (in_array($type, ['credit_note', 'debit_note', 'receipt', 'withholding_tax_receipt'], true)) {
            $invoice = new SalesInvoice(['number' => 'INV-0001']);
            $document->setRelation('invoice', $invoice);
        }

        if ($supplierKind || in_array($type, ['receipt', 'withholding_tax_receipt'], true)) {
            $document->setRelation('bankAccount', new \App\Models\BankAccount(['name' => 'Sample Bank Account']));
        }

        return $document;
    }
}
