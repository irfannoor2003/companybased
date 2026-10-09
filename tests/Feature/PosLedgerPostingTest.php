<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\PosPaymentMethod;
use App\Models\PosSale;
use App\Models\PosShift;
use App\Models\PosSaleItem;
use App\Models\User;
use App\Services\Accounting\LedgerPosting;
use App\Support\InventoryLedger;
use App\Support\Money;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * Point-of-sale posting to the general ledger.
 *
 * The till was the last trading module moving money without touching the ledger:
 * stock moved, a receipt printed, and the revenue simply never appeared. So the
 * income statement excluded every sale rung up at the counter, and inventory kept
 * its full original cost of goods that were long gone from the shelf.
 *
 * Two decisions are worth defending, and the tests below defend them:
 *
 *  - Discounts go to their own contra-revenue account, not netted off revenue.
 *  - A line rung up without a product gets no cost of sales, rather than a
 *    guessed one.
 */
class PosLedgerPostingTest extends TestCase
{
    use SeedsDatabase;

    private ?User $admin = null;

    private function admin(): User
    {
        return $this->admin ??= $this->userForRole('Admin');
    }

    private function openShift(): PosShift
    {
        return PosShift::create([
            'shift_number' => 'SH-'.uniqid(),
            'opened_by' => $this->admin()->id,
            'opened_at' => now(),
            'opening_cash' => '0',
            'status' => 'open',
        ]);
    }

    private function paymentMethod(string $code, bool $isCash): PosPaymentMethod
    {
        return PosPaymentMethod::create([
            'code' => $code,
            'name' => ucfirst($code),
            'is_cash' => $isCash,
            'is_active' => true,
        ]);
    }

    /**
     * A sale built directly, with an explicit cost price on the product.
     */
    private function sale(array $overrides = [], array $lines = []): PosSale
    {
        $sale = PosSale::create(array_merge([
            'receipt_number' => 'RC-'.uniqid(),
            'shift_id' => $this->openShift()->id,
            'subtotal' => '0',
            'discount' => '0',
            'tax' => '0',
            'total' => '0',
            'status' => 'completed',
            'sold_at' => now(),
        ], $overrides));

        foreach ($lines as $line) {
            PosSaleItem::create(array_merge([
                'pos_sale_id' => $sale->id,
                'item_name' => 'Item',
                'quantity' => '1',
                'unit_price' => '0',
                'line_total' => '0',
            ], $line));
        }

        return $sale->fresh();
    }

    /**
     * Debit and credit totals per account code.
     *
     * @return array<string, array{debit: string, credit: string}>
     */
    private function postedTotals(JournalEntry $entry): array
    {
        $entry->load('items.account');

        $totals = [];

        foreach ($entry->items as $item) {
            $code = $item->account->code;
            $totals[$code] ??= ['debit' => '0.00', 'credit' => '0.00'];

            $totals[$code]['debit'] = Money::add($totals[$code]['debit'], $item->debit);
            $totals[$code]['credit'] = Money::add($totals[$code]['credit'], $item->credit);
        }

        return $totals;
    }

    private function assertBalanced(JournalEntry $entry): void
    {
        $entry->load('items');

        $debits = '0.00';
        $credits = '0.00';

        foreach ($entry->items as $item) {
            $debits = Money::add($debits, $item->debit);
            $credits = Money::add($credits, $item->credit);
        }

        $this->assertSame($debits, $credits, 'the till journal must balance');
    }

    public function test_a_till_sale_posts_revenue_tax_and_cash(): void
    {
        // 100 goods + 10 tax = 110 collected
        $sale = $this->sale([
            'subtotal' => '100.00',
            'tax' => '10.00',
            'total' => '110.00',
            'amount_paid' => '110.00',
        ]);

        $entry = app(LedgerPosting::class)->posSaleCompleted($sale);

        $this->assertNotNull($entry, 'the till sale produced no journal');
        $this->assertBalanced($entry);

        $totals = $this->postedTotals($entry);

        $this->assertSame('110.00', $totals['1000']['debit'], 'cash collected');
        $this->assertSame('100.00', $totals['4000']['credit'], 'revenue net of tax');
        $this->assertSame('10.00', $totals['2200']['credit'], 'output tax collected');
    }

    /**
     * A discount has to be visible as a discount. Netted off revenue, a heavy run
     * of discounts and a light one both read as "revenue" and margin stops meaning
     * anything.
     */
    public function test_a_discount_is_posted_to_contra_revenue_not_netted_off(): void
    {
        // 100 goods - 20 discount + 8 tax = 88 collected
        $sale = $this->sale([
            'subtotal' => '100.00',
            'discount' => '20.00',
            'tax' => '8.00',
            'total' => '88.00',
            'amount_paid' => '88.00',
        ]);

        $entry = app(LedgerPosting::class)->posSaleCompleted($sale);
        $totals = $this->postedTotals($entry);

        $this->assertSame('20.00', $totals['4200']['debit'], 'the discount is visible on its own account');

        // Revenue is recognised GROSS at 100, with the 20 concession booked
        // separately. Crediting 80 here as well would deduct the discount twice.
        $this->assertSame('100.00', $totals['4000']['credit'], 'revenue is the full line value');
        $this->assertSame('8.00', $totals['2200']['credit'], 'tax collected is a liability');
        $this->assertSame('88.00', $totals['1000']['debit'], 'only what the customer actually paid is banked');
        $this->assertBalanced($entry);
    }

    public function test_a_sale_with_no_discount_posts_no_discount_line(): void
    {
        $sale = $this->sale([
            'subtotal' => '50.00', 'total' => '50.00', 'amount_paid' => '50.00',
        ]);

        $entry = app(LedgerPosting::class)->posSaleCompleted($sale);

        $this->assertArrayNotHasKey('4200', $this->postedTotals($entry));
    }

    /**
     * Stock left the shelf, so its cost has to leave the balance sheet with it.
     */
    public function test_cost_of_goods_sold_and_inventory_are_relieved(): void
    {
        [$product] = $this->productWithStockItem('Costed Widget');

        $product->update(['cost_price' => '6.00']);

        $sale = $this->sale(
            ['subtotal' => '20.00', 'total' => '20.00', 'amount_paid' => '20.00'],
            [['product_id' => $product->id, 'item_name' => 'Costed Widget', 'quantity' => '2', 'unit_price' => '10.00', 'line_total' => '20.00']],
        );

        $entry = app(LedgerPosting::class)->posSaleCompleted($sale);
        $totals = $this->postedTotals($entry);

        $this->assertSame('12.00', $totals['5000']['debit'], 'cost of the two units sold');
        $this->assertSame('12.00', $totals['1200']['credit'], 'inventory relieved at cost');
        $this->assertBalanced($entry);
    }

    /**
     * A line rung up by name has no product and therefore no known cost. Posting a
     * guessed one would put a margin on the books that cannot be substantiated, so
     * the gap is left visible as an un-costed sale instead.
     */
    public function test_a_line_without_a_product_gets_no_invented_cost(): void
    {
        $sale = $this->sale(
            ['subtotal' => '25.00', 'total' => '25.00', 'amount_paid' => '25.00'],
            [['product_id' => null, 'item_name' => 'Loose Item', 'quantity' => '1', 'unit_price' => '25.00', 'line_total' => '25.00']],
        );

        $entry = app(LedgerPosting::class)->posSaleCompleted($sale);
        $totals = $this->postedTotals($entry);

        $this->assertArrayNotHasKey('5000', $totals, 'a cost was invented for a line with no product');
        $this->assertArrayNotHasKey('1200', $totals);
        $this->assertSame('25.00', $totals['4000']['credit']);
        $this->assertBalanced($entry);
    }

    public function test_cost_is_taken_per_line_not_from_the_header(): void
    {
        [$cheap] = $this->productWithStockItem('Cheap');
        [$dear] = $this->productWithStockItem('Dear');
        $cheap->update(['cost_price' => '2.00']);
        $dear->update(['cost_price' => '10.00']);

        $sale = $this->sale(
            ['subtotal' => '70.00', 'total' => '70.00', 'amount_paid' => '70.00'],
            [
                ['product_id' => $cheap->id, 'item_name' => 'Cheap', 'quantity' => '3', 'unit_price' => '10.00', 'line_total' => '30.00'],
                ['product_id' => $dear->id, 'item_name' => 'Dear', 'quantity' => '4', 'unit_price' => '10.00', 'line_total' => '40.00'],
            ],
        );

        $entry = app(LedgerPosting::class)->posSaleCompleted($sale);
        $totals = $this->postedTotals($entry);

        // 3 x 2.00 + 4 x 10.00
        $this->assertSame('46.00', $totals['5000']['debit']);
        $this->assertBalanced($entry);
    }

    /**
     * A card sale settles the bank, not the till. Crediting cash for a card sale
     * is how a business ends up with a till full of money it never received.
     */
    public function test_a_card_sale_settles_the_bank_not_the_cash_till(): void
    {
        $card = $this->paymentMethod('card-'.uniqid(), false);

        $sale = $this->sale([
            'subtotal' => '40.00', 'total' => '40.00', 'amount_paid' => '40.00',
            'payment_method_id' => $card->id,
        ]);

        $entry = app(LedgerPosting::class)
            ->posSaleCompleted($sale, LedgerPosting::BANK);

        $totals = $this->postedTotals($entry);

        $this->assertSame('40.00', $totals['1010']['debit'], 'the card settled through the bank');
        $this->assertArrayNotHasKey('1000', $totals, 'a card sale was credited to the cash till');
    }

    public function test_a_cash_sale_settles_the_cash_till(): void
    {
        $cash = $this->paymentMethod('cash-'.uniqid(), true);

        $sale = $this->sale([
            'subtotal' => '40.00', 'total' => '40.00', 'amount_paid' => '40.00',
            'payment_method_id' => $cash->id,
        ]);

        $entry = app(LedgerPosting::class)
            ->posSaleCompleted($sale, LedgerPosting::CASH);

        $this->assertSame('40.00', $this->postedTotals($entry)['1000']['debit']);
    }

    public function test_change_given_does_not_enter_the_ledger(): void
    {
        // 30 goods, customer hands over 50, so 20 is change and 30 is revenue.
        $sale = $this->sale([
            'subtotal' => '30.00', 'total' => '30.00',
            'amount_paid' => '50.00', 'change_due' => '20.00',
        ]);

        $entry = app(LedgerPosting::class)->posSaleCompleted($sale);
        $totals = $this->postedTotals($entry);

        $this->assertSame('30.00', $totals['1000']['debit'], 'only the sale is banked; the change was handed over');
        $this->assertSame('30.00', $totals['4000']['credit']);
    }

    public function test_a_sale_cannot_post_twice(): void
    {
        $sale = $this->sale(['subtotal' => '60.00', 'total' => '60.00', 'amount_paid' => '60.00']);

        $ledger = app(LedgerPosting::class);
        $first = $ledger->posSaleCompleted($sale);
        $second = $ledger->posSaleCompleted($sale);

        $this->assertNotNull($first);
        $this->assertNull($second, 'a till retry posted a duplicate journal');
    }

    public function test_a_zero_total_sale_posts_nothing(): void
    {
        $sale = $this->sale();

        $this->assertNull(app(LedgerPosting::class)->posSaleCompleted($sale));
    }

    public function test_the_sale_posts_through_the_till_screen(): void
    {
        $warehouse = $this->warehouse();
        [$product, $item] = $this->productWithStockItem('Tilled Widget');
        $product->update(['cost_price' => '4.00']);

        InventoryLedger::adjust($item->id, $warehouse->id, '10', 'initial');
        $this->openShift();

        $this->actingAs($this->admin())
            ->post('/pos', [
                'items' => [
                    ['product_id' => $product->id, 'name' => 'Tilled Widget', 'qty' => '2', 'price' => '15.00'],
                ],
                'amount_paid' => '100',
            ])
            ->assertSessionHasNoErrors();

        $sale = PosSale::latest('id')->firstOrFail();

        $this->assertTrue(
            app(LedgerPosting::class)->hasJournalFor($sale),
            'a till sale was recorded with no journal behind it',
        );

        $entry = app(LedgerPosting::class)->primaryJournalFor($sale);
        $this->assertNotNull($entry);
        $totals = $this->postedTotals($entry);

        $this->assertSame('30.00', $totals['4000']['credit'], 'the till sale recognised revenue');
        $this->assertSame('8.00', $totals['5000']['debit'], 'and relieved stock at cost');
    }

    public function test_inventory_is_reduced_by_the_cost_of_what_was_sold(): void
    {
        [$product] = $this->productWithStockItem('Margined Widget');
        $product->update(['cost_price' => '7.00']);

        $sale = $this->sale(
            ['subtotal' => '25.00', 'total' => '25.00', 'amount_paid' => '25.00'],
            [['product_id' => $product->id, 'item_name' => 'Margined Widget', 'quantity' => '5', 'unit_price' => '5.00', 'line_total' => '25.00']],
        );

        app(LedgerPosting::class)->posSaleCompleted($sale);

        $account = Account::where('code', '1200')->firstOrFail();

        $balance = '0.00';
        foreach ($account->journalItems()->get() as $line) {
            $balance = Money::subtract(Money::add($balance, $line->debit), $line->credit);
        }

        // 5 x 7.00 cost of goods sold, relieved from inventory
        $this->assertSame('-35.00', $balance, 'inventory still carries the cost of goods already sold');
    }
}
