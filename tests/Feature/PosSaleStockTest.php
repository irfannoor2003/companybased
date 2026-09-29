<?php

namespace Tests\Feature;

use App\Models\InventoryMovement;
use App\Models\PosSale;
use App\Models\PosShift;
use App\Models\PosShift as Shift;
use App\Models\User;
use App\Support\InventoryLedger;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * A till sale is a real stock movement and a real financial document, so it has
 * to obey the same ledger invariants as every other module:
 *
 *  - selling decrements tracked stock (POS used to leave on-hand untouched,
 *    so it drifted permanently upward after any sale)
 *  - a sale that would drive stock negative is rejected outright
 *  - the header subtotal equals the sum of the printed line totals
 *  - the sale and its line items are written atomically
 */
class PosSaleStockTest extends TestCase
{
    use SeedsDatabase;

    private function openShift(): Shift
    {
        return PosShift::create([
            'shift_number' => 'SH-1',
            'opened_by' => $this->admin()->id,
            'opened_at' => now(),
            'opening_cash' => '0',
            'status' => 'open',
        ]);
    }

    private ?User $admin = null;

    /**
     * Memoised: userForRole() creates the row, so calling it twice in one test
     * would collide on the unique email.
     */
    private function admin(): User
    {
        return $this->admin ??= $this->userForRole('Admin');
    }

    private function payload(array $items, array $overrides = []): array
    {
        return array_merge([
            'items' => $items,
            'amount_paid' => '1000',
        ], $overrides);
    }

    public function test_a_sale_decrements_tracked_stock(): void
    {
        $warehouse = $this->warehouse();
        [$product, $item] = $this->productWithStockItem('Till Widget');
        $this->openShift();

        InventoryLedger::adjust($item->id, $warehouse->id, '50', 'initial');

        $this->actingAs($this->admin())
            ->post('/pos', $this->payload([
                ['product_id' => $product->id, 'name' => 'Till Widget', 'qty' => '5', 'price' => '10.00'],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('45', InventoryLedger::onHand($item->id), 'a till sale must decrement on-hand');

        $movement = InventoryMovement::where('item_id', $item->id)
            ->where('movement_type', 'pos_sale')
            ->first();

        $this->assertNotNull($movement, 'the sale must be recorded in the ledger');
        $this->assertEqualsWithDelta(-5.0, (float) $movement->quantity_change, 0.0005);
    }

    public function test_the_sale_is_referenceable_from_its_ledger_movement(): void
    {
        $warehouse = $this->warehouse();
        [$product, $item] = $this->productWithStockItem('Referenced Widget');
        $this->openShift();

        InventoryLedger::adjust($item->id, $warehouse->id, '10', 'initial');

        $this->actingAs($this->admin())
            ->post('/pos', $this->payload([
                ['product_id' => $product->id, 'name' => 'Referenced Widget', 'qty' => '1', 'price' => '5.00'],
            ]));

        $sale = PosSale::latest('id')->firstOrFail();
        $movement = InventoryMovement::where('movement_type', 'pos_sale')->firstOrFail();

        $this->assertSame($sale->getMorphClass(), $movement->reference_type);
        $this->assertSame($sale->id, $movement->reference_id);
    }

    public function test_a_sale_beyond_available_stock_is_rejected(): void
    {
        $warehouse = $this->warehouse();
        [$product, $item] = $this->productWithStockItem('Scarce Widget');
        $this->openShift();

        InventoryLedger::adjust($item->id, $warehouse->id, '3', 'initial');

        $this->actingAs($this->admin())
            ->post('/pos', $this->payload([
                ['product_id' => $product->id, 'name' => 'Scarce Widget', 'qty' => '10', 'price' => '5.00'],
            ]))
            ->assertSessionHas('toasts');

        $this->assertSame('3', InventoryLedger::onHand($item->id), 'stock must be unchanged after a rejected sale');
        $this->assertSame(0, PosSale::count(), 'a rejected sale must not be persisted');
    }

    public function test_the_subtotal_equals_the_sum_of_its_rounded_line_totals(): void
    {
        $this->openShift();

        // Three lines that each round up to 0.01 but whose raw float sum is
        // 0.015. The header must report 0.03 so the receipt adds up.
        $this->actingAs($this->admin())
            ->post('/pos', $this->payload([
                ['product_id' => null, 'name' => 'Fraction A', 'qty' => '1', 'price' => '0.005'],
                ['product_id' => null, 'name' => 'Fraction B', 'qty' => '1', 'price' => '0.005'],
                ['product_id' => null, 'name' => 'Fraction C', 'qty' => '1', 'price' => '0.005'],
            ]))
            ->assertSessionHasNoErrors();

        $sale = PosSale::latest('id')->firstOrFail();

        $this->assertSame('0.03', $sale->subtotal);
        $this->assertSame('0.03', $sale->total);
    }

    public function test_a_discount_larger_than_the_subtotal_is_rejected(): void
    {
        $this->openShift();

        $this->actingAs($this->admin())
            ->post('/pos', $this->payload(
                [['product_id' => null, 'name' => 'Cheap', 'qty' => '1', 'price' => '10.00']],
                ['discount' => '25.00', 'amount_paid' => '0']
            ))
            ->assertSessionHas('toasts');

        $this->assertSame(0, PosSale::count(), 'no sale may be created with a negative total');
    }

    public function test_a_sale_cannot_be_recorded_without_an_open_shift(): void
    {
        $this->actingAs($this->admin())
            ->post('/pos', $this->payload([
                ['product_id' => null, 'name' => 'No Shift', 'qty' => '1', 'price' => '10.00'],
            ]))
            ->assertSessionHas('toasts');

        $this->assertSame(0, PosSale::count());
    }
}
