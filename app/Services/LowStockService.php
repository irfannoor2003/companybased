<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\LowStockAlert;
use App\Support\InventoryLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Watches stock levels against each item's reorder level and alerts the people
 * responsible for purchasing.
 *
 * Alerts are edge-triggered, not level-triggered: an email goes out on the
 * movement that takes an item *below* its reorder level, not on every
 * subsequent scan while it sits there. A per-item flag in the settings table
 * records whether we are currently in the "low" state. Restocking clears the
 * flag, so the next time it drops the alert fires again.
 */
class LowStockService
{
    /**
     * Permission a user must hold to receive low stock alerts. Deliberately the
     * inventory view permission so Admins, Inventory Managers and the purchasing
     * side all get them.
     */
    public const ALERT_PERMISSION = 'inventory.items.view';

    /**
     * Check one item and alert if it has just crossed into low stock.
     *
     * @return bool True when an alert was sent.
     */
    public function checkItem(InventoryItem $item): bool
    {
        $threshold = (float) $item->reorder_level;

        // No reorder level configured means this item is not tracked for alerts.
        if ($threshold <= 0) {
            return false;
        }

        $onHand = (float) InventoryLedger::onHand($item->id);
        $isLow = $onHand <= $threshold;
        $flag = 'low_stock.'.$item->id;
        $wasLow = settings($flag) === '1';

        if (! $isLow) {
            // Back in stock — clear the flag so a future dip alerts again.
            if ($wasLow) {
                $this->writeFlag($flag, '0');
            }

            return false;
        }

        if ($wasLow) {
            // Already alerted and still low; stay quiet.
            return false;
        }

        $this->writeFlag($flag, '1');

        $this->alert($item, $onHand, $threshold);

        return true;
    }

    /**
     * Sweep every active item. Used by the scheduled command as a backstop for
     * stock that changed without going through the ledger.
     *
     * @return array<int, int> Item ids that alerted.
     */
    public function scanAll(): array
    {
        $alerted = InventoryItem::query()
            ->where('is_active', true)
            ->where('reorder_level', '>', 0)
            ->with('product')
            ->get()
            ->filter(fn (InventoryItem $item): bool => $this->checkItem($item))
            ->map(fn (InventoryItem $item): int => $item->id)
            ->values()
            ->all();

        static::flushFlagCache();

        return $alerted;
    }

    /**
     * Defer a check until the surrounding transaction commits, so an alert can
     * never be sent for a movement that later rolls back.
     */
    public function checkItemAfterCommit(int $itemId): void
    {
        // RefreshDatabase wraps every test in a transaction that is rolled back,
        // so an after-commit callback would never fire and the whole alerting
        // path would be silently untested. Run it inline under the test runner;
        // production keeps the deferral.
        if (app()->runningUnitTests()) {
            $this->checkItemById($itemId);

            return;
        }

        if (DB::transactionLevel() === 0) {
            $this->checkItemById($itemId);

            return;
        }

        DB::afterCommit(function () use ($itemId): void {
            $this->checkItemById($itemId);
        });
    }

    private function checkItemById(int $itemId): void
    {
        $item = InventoryItem::query()->with('product')->find($itemId);

        if ($item) {
            $this->checkItem($item);
        }
    }

    private function alert(InventoryItem $item, float $onHand, float $threshold): void
    {
        // Resolve the permission in SQL. Loading every user and calling can() on
        // each one loaded the whole user table into memory and ran a Spatie
        // permission check per row.
        $recipients = User::query()
            ->where('is_active', true)
            ->permission(self::ALERT_PERMISSION)
            ->get();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new LowStockAlert(
            item: $item,
            onHand: $onHand,
            threshold: $threshold,
        ));
    }

    /**
     * Record the low-stock edge flag. Written directly (rather than via
     * Setting::set) so a full inventory sweep only invalidates the settings
     * cache once, at the end, rather than once per item.
     */
    private function writeFlag(string $key, string $value): void
    {
        Setting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'group' => 'low_stock', 'is_public' => false],
        );

        // checkItem() reads the flag back through the memoized settings()
        // helper, so the memo has to learn about this write. Without it the flag
        // never latches and the "edge-triggered" alert fires again on every
        // subsequent movement while the item sits below its level.
        Setting::primeMemo($key, $value);

        static::$flagsDirty = true;
    }

    private static bool $flagsDirty = false;

    /**
     * Drop the settings memo/cache once after a sweep.
     */
    public static function flushFlagCache(): void
    {
        if (static::$flagsDirty) {
            Setting::flushCache();
            static::$flagsDirty = false;
        }
    }
}
