<?php

namespace Tests\Feature;

use App\Models\InventoryStock;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\LowStockAlert;
use App\Services\LowStockService;
use App\Support\InventoryLedger;
use Illuminate\Support\Facades\Notification;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * Low-stock alerting is edge-triggered: an alert goes out on the movement that
 * takes an item *below* its reorder level, not on every later scan while it sits
 * there, and restocking re-arms it.
 *
 * This whole path used to be unreachable from the test suite. It runs through
 * DB::afterCommit, and RefreshDatabase wraps each test in a transaction that is
 * rolled back, so the callback never fired — the 12 InventoryLedgerTest tests all
 * passed while this code never executed once.
 */
class LowStockAlertTest extends TestCase
{
    use SeedsDatabase;

    private function trackedItem(string $name, string $reorderLevel, string $reorderQty = '20'): array
    {
        return $this->productWithStockItem($name, $reorderLevel, $reorderQty);
    }

    public function test_dipping_below_the_reorder_level_alerts_the_purchasing_team(): void
    {
        Notification::fake();
        $warehouse = $this->warehouse();
        [, $item] = $this->trackedItem('Dipper', '10');
        $this->userForRole('Inventory Manager');

        InventoryLedger::adjust($item->id, $warehouse->id, '50', 'initial');

        Notification::assertNothingSent();

        // The movement that crosses the threshold is the one that alerts.
        InventoryLedger::adjust($item->id, $warehouse->id, '-45', 'write_off');

        Notification::assertSentTo(
            User::where('email', 'inventory-manager@example.test')->first(),
            LowStockAlert::class,
        );
    }

    public function test_an_item_with_no_reorder_level_is_never_alerted(): void
    {
        Notification::fake();
        $warehouse = $this->warehouse();
        [, $item] = $this->trackedItem('Untracked', '0');
        $this->userForRole('Inventory Manager');

        InventoryLedger::adjust($item->id, $warehouse->id, '100', 'initial');
        InventoryLedger::adjust($item->id, $warehouse->id, '-90', 'write_off');

        Notification::assertNothingSent();
    }

    public function test_it_stays_quiet_while_the_item_remains_low(): void
    {
        Notification::fake();
        $warehouse = $this->warehouse();
        [, $item] = $this->trackedItem('Still Low', '10');
        $this->userForRole('Inventory Manager');

        InventoryLedger::adjust($item->id, $warehouse->id, '50', 'initial');
        InventoryLedger::adjust($item->id, $warehouse->id, '-45', 'write_off');

        Notification::fake();
        Notification::assertNothingSent();

        // A second dip while still below the level must not re-alert.
        InventoryLedger::adjust($item->id, $warehouse->id, '-2', 'write_off');

        Notification::assertNothingSent();
    }

    public function test_restocking_re_arms_the_alert_for_the_next_dip(): void
    {
        Notification::fake();
        $warehouse = $this->warehouse();
        [, $item] = $this->trackedItem('Re-armed', '10');
        $recipient = $this->userForRole('Inventory Manager');

        InventoryLedger::adjust($item->id, $warehouse->id, '50', 'initial');
        InventoryLedger::adjust($item->id, $warehouse->id, '-45', 'write_off');

        // Back in stock: the low flag is cleared.
        InventoryLedger::adjust($item->id, $warehouse->id, '100', 'adjustment');

        $this->assertSame('0', settings('low_stock.'.$item->id), 'restocking must clear the low flag');

        Notification::fake();

        // Falling below the level again alerts once more.
        InventoryLedger::adjust($item->id, $warehouse->id, '-95', 'write_off');

        Notification::assertSentTo($recipient, LowStockAlert::class);
    }

    public function test_check_item_reports_whether_it_alerted(): void
    {
        Notification::fake();
        $warehouse = $this->warehouse();
        [, $item] = $this->trackedItem('Reported', '10');
        $this->userForRole('Inventory Manager');

        InventoryLedger::adjust($item->id, $warehouse->id, '50', 'initial');
        InventoryLedger::adjust($item->id, $warehouse->id, '-45', 'write_off');

        $service = app(LowStockService::class);

        // Already flagged, so a repeat check is a no-op.
        $this->assertFalse($service->checkItem($item->fresh()));

        $service->flushFlagCache();
    }

    public function test_a_rejected_movement_never_produces_an_alert(): void
    {
        $warehouse = $this->warehouse();
        [, $item] = $this->trackedItem('Rejected', '10');
        $this->userForRole('Inventory Manager');

        // 50 in stock against a level of 10 is comfortably above it.
        InventoryLedger::adjust($item->id, $warehouse->id, '50', 'initial');

        Notification::fake();

        try {
            // Driving stock negative is refused, so no low-stock check runs.
            InventoryLedger::adjust($item->id, $warehouse->id, '-60', 'write_off');
            $this->fail('expected the movement to be rejected');
        } catch (\DomainException) {
            // expected
        }

        Notification::assertNothingSent();
    }

    public function test_the_scan_sweep_ignores_items_without_a_reorder_level(): void
    {
        Notification::fake();

        $warehouse = $this->warehouse();
        [, $tracked] = $this->trackedItem('Sweepable', '10');
        [, $untracked] = $this->trackedItem('Sweep Skip', '0');
        $this->userForRole('Inventory Manager');

        InventoryLedger::adjust($tracked->id, $warehouse->id, '50', 'initial');
        InventoryLedger::adjust($untracked->id, $warehouse->id, '50', 'initial');

        // Drive the tracked item below its level without going through the
        // ledger, which is exactly the "stock changed behind our back" case
        // scanAll() exists to backstop.
        Setting::query()->where('key', 'low_stock.'.$tracked->id)->delete();
        Setting::flushCache();
        InventoryStock::query()->where('item_id', $tracked->id)->update(['quantity' => 2]);

        $alerted = app(LowStockService::class)->scanAll();

        $this->assertContains($tracked->id, $alerted, 'a low tracked item must alert on the sweep');
        $this->assertNotContains($untracked->id, $alerted, 'an item with no reorder level is never swept');
    }
}
