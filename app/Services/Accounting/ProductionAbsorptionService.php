<?php

namespace App\Services\Accounting;

use App\Models\Employee;
use App\Models\InventoryProductionOrder;
use App\Models\JournalEntry;
use App\Models\ProductionLabourEntry;
use App\Models\SalaryStructure;
use App\Models\Setting;
use App\Support\Money;

/**
 * Absorbs labour and manufacturing overhead into work in progress.
 *
 * Factory labour was previously expensed in one monthly total. That is wrong for a
 * manufacturer: the cost of building goods sits in period expense instead of in the
 * goods themselves, so inventory is understated and every manufactured item's margin
 * is overstated by whatever it cost to make.
 *
 * THE POSTING IS A RECLASSIFICATION, NOT A NEW COST. This is the part worth arguing:
 *
 *   Dr Work in Progress          labour + overhead absorbed
 *       Cr Salaries and Wages          labour, moving out of period expense
 *       Cr Manufacturing Overhead      overhead, moving out of period expense
 *
 * An approved payroll run already posted Dr 6200 / Cr 2150 Net Pay Payable. That
 * journal is correct and must not be touched: the wages really were incurred, and
 * the money really is owed to staff. Absorption therefore CREDITS THE EXPENSE
 * ACCOUNT, 6200, to move that cost across into inventory.
 *
 * The obvious alternative -- crediting 2150 Net Pay Payable again -- would be wrong
 * in a way that is very hard to see later. It would double the payable: the business
 * would owe staff twice for the same hours, and the balance sheet would carry a
 * liability nobody ever paid. It would also leave 6200 fully expensed, so the cost
 * would be counted twice in total -- once as expense and once as inventory.
 *
 * So labour is MOVED from expense to inventory. Total cost is unchanged. Only its
 * location on the balance sheet changes, which is exactly what absorption means.
 *
 * Overhead is absorbed on a DIRECT LABOUR HOUR basis rather than per unit or per
 * machine hour. There is no work centre, machine or equipment record anywhere in
 * this application, so a machine-hour rate would have nothing to measure against.
 * Labour hours are recorded, so they are the basis. This is a real limitation, and
 * it is stated rather than hidden behind a setting that implies more precision than
 * the underlying data supports.
 */
class ProductionAbsorptionService
{
    public const WORK_IN_PROGRESS = '1350';
    public const MANUFACTURING_OVERHEAD = '1450';
    public const SALARIES_AND_WAGES = '6200';

    /**
     * Standard monthly hours used to turn a monthly salary into an hourly rate.
     *
     * 26 working days x 8 hours. Salary structures in this application are monthly
     * because payroll is monthly, but absorption is per hour, so a conversion is
     * unavoidable and it has to be one explicit, arguable number. Overridable in
     * settings because 6-day weeks and 7.5-hour days are both normal.
     */
    public const DEFAULT_MONTHLY_HOURS = 208;

    /**
     * Move an order's unabsorbed labour and overhead into work in progress.
     *
     * Idempotent per entry: each labour row is its own posting key, so re-running
     * absorption cannot move the same hours twice.
     */
    public function absorb(InventoryProductionOrder $order, ?string $date = null): ?JournalEntry
    {
        $entries = ProductionLabourEntry::query()
            ->where('production_order_id', $order->id)
            ->unabsorbed()
            ->orderBy('id')
            ->get();

        $last = null;

        foreach ($entries as $entry) {
            $last = $this->absorbLabour($entry, $date) ?? $last;
        }

        return $last ?? $this->absorbOverhead($order, $date);
    }

    /**
     * Dr WIP / Cr Salaries and Wages, then mark the entry absorbed.
     */
    public function absorbLabour(ProductionLabourEntry $entry, ?string $date = null): ?JournalEntry
    {
        if ($entry->absorbed_at !== null) {
            return null;
        }

        $cost = $entry->labour_cost;

        if (Money::equals($cost, '0')) {
            return null;
        }

        $entry->productionOrder;

        $posted = app(LedgerPosting::class)->post(
            $entry,
            [
                [
                    'code' => self::WORK_IN_PROGRESS,
                    'debit' => $cost,
                    'memo' => 'Direct labour absorbed into production',
                ],
                [
                    'code' => self::SALARIES_AND_WAGES,
                    'credit' => $cost,
                    'memo' => 'Direct labour capitalised out of period expense',
                ],
            ],
            'Labour absorbed to production order '.$entry->productionOrder?->number,
            $date ?? $entry->work_date?->toDateString(),
            stage: 'absorbed',
        );

        if ($posted !== null) {
            // Marked only after the journal exists. Marking first would leave the
            // hours stranded in period expense with no way to tell they were due
            // to be capitalised, if the posting then failed.
            $entry->update(['absorbed_at' => now()]);
        }

        return $posted;
    }

    /**
     * Overhead applied on direct labour hours.
     *
     * Rate is a setting, defaulting to zero. Zero is the right default rather than a
     * guess: absorbing overhead that was never configured would invent factory cost
     * out of nothing and put it in inventory.
     */
    public function absorbOverhead(InventoryProductionOrder $order, ?string $date = null): ?JournalEntry
    {
        $rate = (float) Setting::get('production.overhead_rate_per_hour', 0);

        if ($rate <= 0.0) {
            return null;
        }

        $minutes = (int) ProductionLabourEntry::query()
            ->where('production_order_id', $order->id)
            ->sum('minutes');

        if ($minutes <= 0) {
            return null;
        }

        $overhead = Money::of(Money::multiply((string) $rate, (string) ($minutes / 60)));

        if (Money::equals($overhead, '0')) {
            return null;
        }

        return app(LedgerPosting::class)->post(
            $order,
            [
                [
                    'code' => self::WORK_IN_PROGRESS,
                    'debit' => $overhead,
                    'memo' => 'Manufacturing overhead absorbed',
                ],
                [
                    'code' => self::MANUFACTURING_OVERHEAD,
                    'credit' => $overhead,
                    'memo' => 'Overhead capitalised out of period expense',
                ],
            ],
            'Overhead absorbed to production order '.$order->number,
            $date ?? now()->toDateString(),
            stage: 'overhead',
        );
    }

    /**
     * Total cost sitting in work in progress for an order.
     */
    public function absorbedTotal(InventoryProductionOrder $order): string
    {
        $labour = (string) ProductionLabourEntry::query()
            ->where('production_order_id', $order->id)
            ->whereNotNull('absorbed_at')
            ->sum('labour_cost');

        $rate = (float) Setting::get('production.overhead_rate_per_hour', 0);
        $minutes = (int) ProductionLabourEntry::query()
            ->where('production_order_id', $order->id)
            ->sum('minutes');

        return Money::add(
            Money::of($labour),
            $rate > 0 ? Money::of(Money::multiply((string) $rate, (string) ($minutes / 60))) : '0.00',
        );
    }

    /**
     * An employee's hourly rate, from their active salary structure.
     *
     * Returns null when there is no structure. Null rather than zero, so a caller
     * can tell "this worker has no rate on file" from "this worker costs nothing" --
     * treating unknown as zero would silently absorb labour at no cost and understate
     * the goods it made.
     */
    public function hourlyRateFor(Employee $employee): ?string
    {
        $gross = (float) ($employee->activeSalaryStructure()?->gross() ?? 0);

        if ($gross <= 0.0) {
            return null;
        }

        $hours = $this->standardMonthlyHours();

        if ($hours <= 0) {
            return null;
        }

        return number_format($gross / $hours, 4, '.', '');
    }

    /**
     * Monthly hours used to convert a monthly salary to an hourly rate.
     */
    public function standardMonthlyHours(): float
    {
        $configured = (float) Setting::get('production.standard_monthly_hours', self::DEFAULT_MONTHLY_HOURS);

        return $configured > 0 ? $configured : (float) self::DEFAULT_MONTHLY_HOURS;
    }

    /**
     * Cost for a number of minutes at a given hourly rate.
     */
    public function costForMinutes(int $minutes, string $hourlyRate): string
    {
        return Money::of(Money::multiply($hourlyRate, (string) ($minutes / 60)));
    }
}