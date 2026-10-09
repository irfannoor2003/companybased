<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\InventoryItem;
use App\Models\InventoryProductionOrder;
use App\Models\InventoryWarehouse;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Services\Accounting\ProductionPostingService;
use App\Support\InventoryLedger;
use App\Support\Money;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * Production posting to the general ledger.
 *
 * The stock side of production already existed: completing an order moved
 * components out and finished goods in through InventoryLedger. Nothing reached
 * the ledger, so a completed production order changed every stock figure on the
 * system and none of the accounting ones -- components stayed on the balance sheet
 * at their original cost, finished goods had no cost basis at all, and the
 * difference went nowhere.
 *
 * The two decisions worth testing:
 *
 *  - WIP is relieved to finished goods INVENTORY, not to cost of goods sold.
 *    Nothing has been sold, so reporting a cost here would make margin wrong on
 *    every production order until the goods happened to be invoiced.
 *
 *  - Variance is measured against the finished item's own cost_price, so a
 *    production cost above what the goods are carried at becomes visible instead
 *    of being buried in a mis-stated inventory.
 */
class ProductionPostingTest extends TestCase
{
    use SeedsDatabase;

    private ?InventoryWarehouse $warehouse = null;

    private function warehouse(): InventoryWarehouse
    {
        return $this->warehouse ??= InventoryWarehouse::firstOrCreate(
            ['name' => 'Production'],
            ['code' => 'PROD', 'is_active' => true],
        );
    }

    private function item(string $name, string $costPrice): InventoryItem
    {
        $product = Product::create([
            'name' => $name,
            'sku' => 'SKU-'.strtoupper(substr(md5($name), 0, 6)),
            'cost_price' => $costPrice,
            'retail_price' => '100.00',
            'is_active' => true,
        ]);

        return InventoryItem::firstOrCreate(
            ['product_id' => $product->id],
            ['warehouse_id' => $this->warehouse()->id, 'quantity_on_hand' => 0],
        );
    }

    /**
     * A production order consuming components to build finished goods.
     *
     * @param  array<int, array{0: InventoryItem, 1: float}>  $components  [item, qty used]
     */
    private function order(InventoryItem $finished, string $quantity, array $components): InventoryProductionOrder
    {
        $order = InventoryProductionOrder::create([
            'number' => 'MO-'.uniqid(),
            'item_id' => $finished->id,
            'warehouse_id' => $this->warehouse()->id,
            'quantity' => $quantity,
            'status' => 'in_progress',
        ]);

        foreach ($components as [$component, $qtyUsed]) {
            $order->items()->create([
                'component_item_id' => $component->id,
                'quantity_required' => (string) $qtyUsed,
                'quantity_used' => (string) $qtyUsed,
            ]);
        }

        return $order->fresh();
    }

    /**
     * @return array<string, string>  account code => debit
     */
    private function debitsFor(JournalEntry $entry): array
    {
        $entry->load('items.account');

        $totals = [];
        foreach ($entry->items as $item) {
            $code = $item->account->code;
            $totals[$code] = Money::add($totals[$code] ?? '0.00', $item->debit);
        }

        return $totals;
    }

    private function assertBalanced(JournalEntry $entry): void
    {
        $entry->load('items');

        $this->assertSame(
            Money::of($entry->items->sum('debit')),
            Money::of($entry->items->sum('credit')),
        );
    }

    /**
     * The headline: completing production now reaches the balance sheet.
     */
    public function test_completing_a_production_order_posts_a_journal(): void
    {
        $finished = $this->item('Widget', '50.00');
        $steel = $this->item('Steel', '10.00');

        // Give the components stock to consume.
        InventoryLedger::adjust($steel->id, $this->warehouse()->id, '100', 'initial');

        $order = $this->order($finished, '10', [[$steel, 20.0]]);

        $entry = app(ProductionPostingService::class)->postCompleted($order);

        $this->assertNotNull($entry, 'completing production produced no journal');
        $this->assertBalanced($entry);

        // 20 components at 10 each = 200. The goods are carried at 50 each = 500, so
        // the production came in UNDER cost: nothing is left stranded in work in
        // progress and there is no variance to book.
        $debits = $this->debitsFor($entry);

        $this->assertSame(
            '500.00',
            $debits['1200'] ?? '',
            'the finished goods were not received into inventory',
        );

        $this->assertArrayNotHasKey(
            '1350',
            $debits,
            'a completed production order left cost stranded in work in progress',
        );
    }

    /**
     * Components must leave inventory at their own cost.
     */
    public function test_components_leave_inventory_at_their_own_cost(): void
    {
        $finished = $this->item('Widget', '50.00');
        $steel = $this->item('Steel', '10.00');

        InventoryLedger::adjust($steel->id, $this->warehouse()->id, '100', 'initial');

        $order = $this->order($finished, '10', [[$steel, 20.0]]);

        $entry = app(ProductionPostingService::class)->postCompleted($order);
        $entry->load('items.account');

        $credited = [];
        foreach ($entry->items as $item) {
            $credited[$item->account->code] = Money::add($credited[$item->account->code] ?? '0.00', $item->credit);
        }

        // 200 of components left the shelf. The 500 of goods came IN, so it sits on the
        // debit side rather than here.
        $this->assertSame('200.00', $credited['1200']);
    }

    /**
     * The decision most worth defending: WIP goes to finished-goods inventory,
     * not to cost of goods sold.
     */
    public function test_work_in_progress_relieves_to_inventory_and_never_to_cogs(): void
    {
        $finished = $this->item('Widget', '50.00');
        $steel = $this->item('Steel', '10.00');

        InventoryLedger::adjust($steel->id, $this->warehouse()->id, '100', 'initial');

        $order = $this->order($finished, '10', [[$steel, 20.0]]);

        $entry = app(ProductionPostingService::class)->postCompleted($order);
        $entry->load('items.account');

        $codes = $entry->items->pluck('account.code')->all();

        $this->assertNotContains(
            '5000',
            $codes,
            'production posted to cost of goods sold; nothing has been sold yet',
        );

        $this->assertContains('1200', $codes, 'inventory was not touched');

        /**
         * WIP is deliberately absent.
         *
         * Components in and goods out happen in the same event, so work in progress
         * is empty when the order completes. A WIP line here would leave a phantom
         * balance on an account that just went to zero, and nobody would reconcile
         * it until the balance sheet looked wrong.
         */
        $this->assertNotContains('1350', $codes, 'work in progress carried a balance after completion');
    }

    /**
     * Producing at less than the goods' carried cost is a variance worth seeing.
     */
    public function test_producing_above_the_goods_cost_books_a_variance(): void
    {
        // Components cost 30 each; the finished item is carried at 20.
        $finished = $this->item('Widget', '20.00');
        $steel = $this->item('Steel', '30.00');

        InventoryLedger::adjust($steel->id, $this->warehouse()->id, '100', 'initial');

        $order = $this->order($finished, '10', [[$steel, 20.0]]);

        $entry = app(ProductionPostingService::class)->postCompleted($order);

        $debits = $this->debitsFor($entry);

        // Components 600, goods produced at a carried cost of 200, so 400 was lost
        // in production. That is an expense, and nothing stays in WIP.
        $this->assertSame('400.00', $debits['6700'] ?? '', 'the overrun was not booked as variance');
        $this->assertArrayNotHasKey('1350', $debits, 'a completed order left cost in WIP');
        $this->assertBalanced($entry);
    }

    /**
     * Producing under the goods' carried cost also surfaces, in the other
     * direction -- otherwise it would quietly flatter inventory.
     */
    public function test_producing_below_the_goods_cost_credits_variance(): void
    {
        $finished = $this->item('Widget', '50.00');
        $steel = $this->item('Steel', '10.00');

        InventoryLedger::adjust($steel->id, $this->warehouse()->id, '100', 'initial');

        $order = $this->order($finished, '10', [[$steel, 20.0]]);

        $entry = app(ProductionPostingService::class)->postCompleted($order);
        $entry->load('items.account');

        $credited = [];
        foreach ($entry->items as $item) {
            $credited[$item->account->code] = Money::add($credited[$item->account->code] ?? '0.00', $item->credit);
        }

        // Components 200, goods carried at 500. The goods are held 300 above what they
        // cost to make, so that overstatement is credited back rather than left
        // quietly inflating inventory.
        $this->assertSame(
            '300.00',
            $credited['6700'] ?? '',
            'the underrun was not credited back',
        );
    }

    /**
     * A balanced production order must produce no variance line at all, or the
     * account picks up noise on every single job.
     */
    public function test_a_production_at_standard_cost_books_no_variance(): void
    {
        $finished = $this->item('Widget', '20.00');
        $steel = $this->item('Steel', '20.00');

        InventoryLedger::adjust($steel->id, $this->warehouse()->id, '100', 'initial');

        // 10 components at 20 = 200 in, 10 goods at 20 = 200 out. Genuinely standard,
        // so nothing is over- or under-absorbed and no variance should arise.
        $order = $this->order($finished, '10', [[$steel, 10.0]]);

        $entry = app(ProductionPostingService::class)->postCompleted($order);
        $entry->load('items.account');

        $this->assertNotContains('6700', $entry->items->pluck('account.code')->all());
    }

    /**
     * An order whose components have no known cost must post nothing rather than
     * a zero journal.
     */
    public function test_an_order_with_no_known_cost_posts_nothing(): void
    {
        $finished = $this->item('Widget', '0');
        $steel = $this->item('Steel', '0');

        InventoryLedger::adjust($steel->id, $this->warehouse()->id, '100', 'initial');

        $order = $this->order($finished, '10', [[$steel, 20.0]]);

        $this->assertNull(
            app(ProductionPostingService::class)->postCompleted($order),
            'an order with no cost basis posted a zero journal',
        );
    }

    public function test_completing_twice_posts_once(): void
    {
        $finished = $this->item('Widget', '50.00');
        $steel = $this->item('Steel', '10.00');

        InventoryLedger::adjust($steel->id, $this->warehouse()->id, '100', 'initial');

        $order = $this->order($finished, '10', [[$steel, 20.0]]);

        $service = app(ProductionPostingService::class);
        $first = $service->postCompleted($order);
        $second = $service->postCompleted($order->fresh());

        $this->assertNotNull($first);
        $this->assertNull($second, 'completing twice posted a duplicate journal');
    }

    /**
     * The variance account must not be debited and credited in the same order.
     */
    public function test_the_variance_appears_on_one_side_only(): void
    {
        $finished = $this->item('Widget', '20.00');
        $steel = $this->item('Steel', '30.00');

        InventoryLedger::adjust($steel->id, $this->warehouse()->id, '100', 'initial');

        $entry = app(ProductionPostingService::class)
            ->postCompleted($this->order($finished, '10', [[$steel, 20.0]]));
        $entry->load('items');

        foreach ($entry->items->where('account.code', '6700') as $line) {
            $this->assertTrue(
                (float) $line->debit === 0.0 || (float) $line->credit === 0.0,
                'a variance line carried both a debit and a credit',
            );
        }
    }

    public function test_the_chart_of_accounts_carries_the_production_accounts(): void
    {
        foreach (['1350', '1450', '6700'] as $code) {
            $this->assertNotNull(
                Account::where('code', $code)->first(),
                "account {$code} is missing; production cannot post",
            );
        }
    }
}