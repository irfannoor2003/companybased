<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\InventoryItem;
use App\Models\InventoryWriteOff;
use App\Models\InventoryWarehouse;
use App\Models\Product;
use App\Services\Accounting\LedgerPosting;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * A write-off removed stock but reached no ledger account, so the balance sheet
 * kept carrying the value of goods that no longer existed. The quantity ledger and
 * the general ledger disagreed permanently.
 *
 * Valued at cost, never at sale price: the loss is what the stock was worth to the
 * business, not what it would have fetched.
 */
class WriteOffLedgerPostingTest extends TestCase
{
    use SeedsDatabase;

    private ?\App\Models\User $admin = null;

    private function admin(): \App\Models\User
    {
        return $this->admin ??= $this->userForRole('Admin');
    }

    private function code(string $code): Account
    {
        return Account::where('code', $code)->firstOrFail();
    }

    /**
     * @return array{0: InventoryItem, 1: InventoryWriteOff}
     */
    private function writeOff(float $costPrice, float $quantity): array
    {
        $warehouse = InventoryWarehouse::firstOrCreate(
            ['name' => 'WO Warehouse '.uniqid()],
            ['code' => strtoupper(substr(md5(uniqid()), 0, 6))],
        );

        $product = Product::create([
            'name' => 'Damaged '.uniqid(),
            'sku' => 'SKU-'.strtoupper(substr(md5(uniqid()), 0, 8)),
            'cost_price' => $costPrice,
            'is_active' => true,
        ]);

        $item = InventoryItem::create([
            'product_id' => $product->id,
            'reorder_level' => '0',
            'reorder_quantity' => '0',
            'is_active' => true,
        ]);

        // Give the warehouse enough stock for the write-off to be legal.
        $item->stock()->create([
            'warehouse_id' => $warehouse->id,
            'quantity' => (string) ($quantity + 10),
        ]);

        $writeOff = InventoryWriteOff::create([
            'number' => 'WO-'.uniqid(),
            'warehouse_id' => $warehouse->id,
            'write_off_date' => now()->toDateString(),
            'status' => 'draft',
            'reason' => 'Damaged in transit',
        ]);

        $writeOff->items()->create([
            'item_id' => $item->id,
            'quantity' => (string) $quantity,
            'reason' => 'Damaged',
        ]);

        return [$item, $writeOff];
    }

    public function test_completing_a_write_off_posts_the_valuation_loss(): void
    {
        [, $writeOff] = $this->writeOff(40.00, 3.0);

        $this->actingAs($this->admin())
            ->patch("/inventory/write-offs/{$writeOff->id}/status", ['status' => 'completed'])
            ->assertSessionHas('toasts');

        $this->assertSame(
            120.00,
            (float) $this->code(LedgerPosting::INVENTORY_ADJUSTMENT)->balance(),
            '3 units at a cost of 40 is a 120 valuation loss',
        );

        $this->assertSame(
            -120.00,
            (float) $this->code(LedgerPosting::INVENTORY)->balance(),
            'the inventory asset must fall by the value written off',
        );
    }

    public function test_the_loss_entry_balances(): void
    {
        [, $writeOff] = $this->writeOff(25.00, 2.0);

        $this->actingAs($this->admin())
            ->patch("/inventory/write-offs/{$writeOff->id}/status", ['status' => 'completed'])
            ->assertSessionHas('toasts');

        $entry = app(LedgerPosting::class)->journalFor($writeOff);

        $this->assertNotNull($entry, 'a completed write-off must be posted');
        $this->assertSame('posted', $entry->status);

        $debits = '0';
        $credits = '0';

        foreach ($entry->load('items')->items as $item) {
            $debits = \App\Support\Money::add($debits, $item->debit);
            $credits = \App\Support\Money::add($credits, $item->credit);
        }

        $this->assertSame($debits, $credits);
    }

    /**
     * Completing twice must not recognise the loss twice.
     */
    public function test_the_loss_is_not_posted_twice(): void
    {
        [, $writeOff] = $this->writeOff(30.00, 2.0);

        $this->actingAs($this->admin())
            ->patch("/inventory/write-offs/{$writeOff->id}/status", ['status' => 'completed']);

        $this->actingAs($this->admin())
            ->patch("/inventory/write-offs/{$writeOff->id}/status", ['status' => 'completed']);

        $this->assertSame(
            60.00,
            (float) $this->code(LedgerPosting::INVENTORY_ADJUSTMENT)->balance(),
            'the loss is recognised once however many times the status is set',
        );
    }

    /**
     * An item with no cost price posts nothing rather than a guessed figure.
     */
    public function test_an_item_with_no_cost_price_posts_no_loss(): void
    {
        [, $writeOff] = $this->writeOff(0.00, 2.0);

        $this->actingAs($this->admin())
            ->patch("/inventory/write-offs/{$writeOff->id}/status", ['status' => 'completed'])
            ->assertSessionHas('toasts');

        $this->assertSame(
            0.00,
            (float) $this->code(LedgerPosting::INVENTORY_ADJUSTMENT)->balance(),
            'no cost on file means no invented loss',
        );

        $this->assertNull(
            app(LedgerPosting::class)->journalFor($writeOff),
            'a zero-value write-off should not raise an empty journal',
        );
    }

    public function test_an_uncompleted_write_off_posts_nothing(): void
    {
        [, $writeOff] = $this->writeOff(40.00, 1.0);

        $this->actingAs($this->admin())
            ->patch("/inventory/write-offs/{$writeOff->id}/status", ['status' => 'cancelled'])
            ->assertSessionHas('toasts');

        $this->assertNull(app(LedgerPosting::class)->journalFor($writeOff));
        $this->assertSame(0.00, (float) $this->code(LedgerPosting::INVENTORY_ADJUSTMENT)->balance());
    }
}