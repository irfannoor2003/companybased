<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceItem;
use App\Services\Accounting\LedgerPosting;
use App\Support\DocumentItems;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * Selling from stock posted revenue but no cost of goods sold, and left Inventory
 * untouched. Margin was overstated by the cost of everything sold and stock never
 * left the balance sheet, however much was shipped.
 *
 * The cost is snapshotted onto the sales line when the invoice is saved, which is
 * what stops a later repricing from rewriting a past invoice's margin.
 */
class CostOfGoodsSoldTest extends TestCase
{
    use SeedsDatabase;

    private ?\App\Models\User $admin = null;

    private function admin(): \App\Models\User
    {
        return $this->admin ??= $this->userForRole('Admin');
    }

    private function ledger(): LedgerPosting
    {
        return new LedgerPosting();
    }

    private function code(string $code): Account
    {
        return Account::where('code', $code)->firstOrFail();
    }

    private function product(string $name, float $cost): Product
    {
        return Product::create([
            'name' => $name,
            'sku' => 'SKU-'.strtoupper(substr(md5($name.microtime(true)), 0, 8)),
            'cost_price' => $cost,
            'is_active' => true,
        ]);
    }

    private function invoice(): SalesInvoice
    {
        return SalesInvoice::create([
            'number' => 'INV-'.uniqid(),
            'customer_id' => $this->customer()->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => 'draft',
            'currency' => 'USD',
            'exchange_rate' => 1,
            'subtotal' => '0',
            'tax_amount' => '0',
            'total' => '0',
            'paid_amount' => '0',
            'credit_applied' => '0',
            'credit_note_applied' => '0',
        ]);
    }

    public function test_selling_stock_recognises_the_cost_and_relieves_inventory(): void
    {
        $product = $this->product('Widget', 40.00);

        $invoice = $this->invoice();

        DocumentItems::sync($invoice, [[
            'product_id' => $product->id,
            'description' => 'Widget',
            'qty' => '3',
            'unit_price' => '100.00',
            'discount_percent' => '0',
            'tax_percent' => '0',
        ]]);

        $invoice->update(['subtotal' => '300.00', 'tax_amount' => '0.00', 'total' => '300.00']);

        $entry = $this->ledger()->salesInvoiceIssued($invoice->fresh());

        $this->assertNotNull($entry);
        $this->assertSame(
            120.00,
            (float) $this->code(LedgerPosting::COST_OF_GOODS_SOLD)->balance(),
            '3 units at a cost of 40 is 120 of cost of goods sold',
        );

        $this->assertSame(
            -120.00,
            (float) $this->code(LedgerPosting::INVENTORY)->balance(),
            'inventory must fall by the cost of what was sold',
        );

        $this->assertSame(300.00, (float) $this->code(LedgerPosting::ACCOUNTS_RECEIVABLE)->balance());
        $this->assertSame(-300.00, (float) $this->code(LedgerPosting::SALES_REVENUE)->balance());
    }

    public function test_the_entry_still_balances_with_cost_of_goods_added(): void
    {
        $product = $this->product('Widget', 40.00);
        $invoice = $this->invoice();

        DocumentItems::sync($invoice, [[
            'product_id' => $product->id,
            'description' => 'Widget',
            'qty' => '3',
            'unit_price' => '100.00',
            'discount_percent' => '0',
            'tax_percent' => '10',
        ]]);

        $invoice->update(['subtotal' => '300.00', 'tax_amount' => '30.00', 'total' => '330.00']);

        $entry = $this->ledger()->salesInvoiceIssued($invoice->fresh())->load('items');

        $debits = '0';
        $credits = '0';

        foreach ($entry->items as $item) {
            $debits = \App\Support\Money::add($debits, $item->debit);
            $credits = \App\Support\Money::add($credits, $item->credit);
        }

        $this->assertSame($debits, $credits, 'adding COGS and the inventory credit must keep the entry balanced');
        $this->assertSame('450.00', $debits, '330 receivable + 120 cost');
    }

    /**
     * The reason the cost is snapshotted: repricing the product must not change
     * what a past invoice cost.
     */
    public function test_repricing_the_product_does_not_rewrite_a_past_invoice(): void
    {
        $product = $this->product('Widget', 40.00);
        $invoice = $this->invoice();

        DocumentItems::sync($invoice, [[
            'product_id' => $product->id,
            'description' => 'Widget',
            'qty' => '2',
            'unit_price' => '100.00',
            'discount_percent' => '0',
            'tax_percent' => '0',
        ]]);

        $invoice->update(['subtotal' => '200.00', 'tax_amount' => '0.00', 'total' => '200.00']);

        $item = $invoice->items()->firstOrFail();
        $this->assertSame(40.00, (float) $item->unit_cost, 'the cost is captured on the line');

        $product->update(['cost_price' => 95.00]);

        $this->ledger()->salesInvoiceIssued($invoice->fresh());

        $this->assertSame(
            80.00,
            (float) $this->code(LedgerPosting::COST_OF_GOODS_SOLD)->balance(),
            '2 units at the cost of 40, not the new 95',
        );
    }

    /**
     * A service business has no product costs, so nothing is invented.
     */
    public function test_a_service_invoice_with_no_product_cost_posts_no_cogs(): void
    {
        $invoice = $this->invoice();

        DocumentItems::sync($invoice, [[
            'description' => 'Consulting',
            'qty' => '10',
            'unit_price' => '150.00',
            'discount_percent' => '0',
            'tax_percent' => '0',
        ]]);

        $invoice->update(['subtotal' => '1500.00', 'tax_amount' => '0.00', 'total' => '1500.00']);

        $entry = $this->ledger()->salesInvoiceIssued($invoice->fresh());

        $this->assertNotNull($entry);
        $this->assertSame(0.00, (float) $this->code(LedgerPosting::COST_OF_GOODS_SOLD)->balance());
        $this->assertSame(0.00, (float) $this->code(LedgerPosting::INVENTORY)->balance());
        $this->assertSame(-1500.00, (float) $this->code(LedgerPosting::SALES_REVENUE)->balance());
    }

    public function test_a_product_with_no_cost_price_posts_no_cogs(): void
    {
        $product = $this->product('Sample', 0.00);
        $invoice = $this->invoice();

        DocumentItems::sync($invoice, [[
            'product_id' => $product->id,
            'description' => 'Sample',
            'qty' => '5',
            'unit_price' => '20.00',
            'discount_percent' => '0',
            'tax_percent' => '0',
        ]]);

        $invoice->update(['subtotal' => '100.00', 'tax_amount' => '0.00', 'total' => '100.00']);

        $this->ledger()->salesInvoiceIssued($invoice->fresh());

        $this->assertSame(0.00, (float) $this->code(LedgerPosting::COST_OF_GOODS_SOLD)->balance());
    }

    /**
     * The cost is part of the journal, so amending the invoice re-derives it.
     */
    public function test_amending_the_invoice_recomputes_the_cost(): void
    {
        $product = $this->product('Widget', 40.00);
        $invoice = $this->invoice();

        DocumentItems::sync($invoice, [[
            'product_id' => $product->id,
            'description' => 'Widget',
            'qty' => '2',
            'unit_price' => '100.00',
            'discount_percent' => '0',
            'tax_percent' => '0',
        ]]);

        $invoice->update(['subtotal' => '200.00', 'tax_amount' => '0.00', 'total' => '200.00']);
        $this->ledger()->salesInvoiceIssued($invoice->fresh());

        $this->assertSame(80.00, (float) $this->code(LedgerPosting::COST_OF_GOODS_SOLD)->balance());

        // The invoice is amended to four units.
        DocumentItems::sync($invoice->fresh(), [[
            'product_id' => $product->id,
            'description' => 'Widget',
            'qty' => '4',
            'unit_price' => '100.00',
            'discount_percent' => '0',
            'tax_percent' => '0',
        ]]);

        $invoice->fresh()->update(['subtotal' => '400.00', 'tax_amount' => '0.00', 'total' => '400.00']);

        $this->ledger()->repost(
            $invoice->fresh(),
            fn ($ledger) => $ledger->salesInvoiceIssued($invoice->fresh()),
            'Invoice amended',
        );

        $this->assertSame(160.00, (float) $this->code(LedgerPosting::COST_OF_GOODS_SOLD)->balance());
        $this->assertSame(-160.00, (float) $this->code(LedgerPosting::INVENTORY)->balance());
    }

    /**
     * Only one live journal, so cost is not recognised twice.
     */
    public function test_cost_is_not_double_counted_across_amendments(): void
    {
        $product = $this->product('Widget', 25.00);
        $invoice = $this->invoice();

        DocumentItems::sync($invoice, [[
            'product_id' => $product->id,
            'description' => 'Widget',
            'qty' => '2',
            'unit_price' => '100.00',
            'discount_percent' => '0',
            'tax_percent' => '0',
        ]]);

        $invoice->update(['subtotal' => '200.00', 'tax_amount' => '0.00', 'total' => '200.00']);

        foreach ([['2', '200.00'], ['3', '300.00'], ['4', '400.00']] as [$qty, $total]) {
            // Change the document first, then let the ledger follow -- which is the
            // order a real amendment happens in.
            DocumentItems::sync($invoice->fresh(), [[
                'product_id' => $product->id,
                'description' => 'Widget',
                'qty' => $qty,
                'unit_price' => '100.00',
                'discount_percent' => '0',
                'tax_percent' => '0',
            ]]);

            $invoice->fresh()->update(['subtotal' => $total, 'tax_amount' => '0.00', 'total' => $total]);

            $this->ledger()->repost(
                $invoice->fresh(),
                fn ($ledger) => $ledger->salesInvoiceIssued($invoice->fresh()),
                'Amended',
            );
        }

        $this->assertSame(
            1,
            JournalEntry::where('reference', SalesInvoice::class.':'.$invoice->id)->where('status', 'posted')->count(),
        );

        $this->assertSame(
            100.00,
            (float) $this->code(LedgerPosting::COST_OF_GOODS_SOLD)->balance(),
            'only the final quantity is recognised: 4 units at 25',
        );
    }

    public function test_the_column_is_populated_on_save(): void
    {
        $product = $this->product('Widget', 12.50);
        $invoice = $this->invoice();

        DocumentItems::sync($invoice, [[
            'product_id' => $product->id,
            'description' => 'Widget',
            'qty' => '1',
            'unit_price' => '50.00',
            'discount_percent' => '0',
            'tax_percent' => '0',
        ]]);

        $item = SalesInvoiceItem::where('invoice_id', $invoice->id)->firstOrFail();

        $this->assertSame(12.50, (float) $item->unit_cost);
    }
}