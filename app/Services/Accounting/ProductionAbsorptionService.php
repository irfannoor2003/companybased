<?php

namespace App\Services\Accounting;

use App\Models\Employee;
use App\Models\InventoryProductionOrder;
use App\Models\InventoryProductionOrderOperation;
use App\Models\JournalEntry;
use App\Models\ProductionLabourEntry;
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
 * OVERHEAD IS ABSORBED ON A CHOSEN BASIS, AND THE BASIS IS A SETTING.
 *
 * `production.absorption_basis` selects it:
 *
 *   labour_hour      (default)  overhead_rate_per_hour x recorded direct labour hours
 *   work_centre_hour             each work centre's own hourly_rate x its own minutes
 *
 * Labour hours were the original basis because nothing measured a machine hour. That
 * reason expired when work centres arrived, but the basis was NOT switched
 * underneath existing users: a shop has to pick deliberately, because the two produce
 * different inventory values for the same job and silently repricing every job in a
 * live ledger is not a change anybody should have to discover in a margin report.
 *
 * Work centres record the minutes a job actually took, so the machine-hour basis is
 * now measurable rather than hypothetical. It is still not the default, and the
 * difference is worth stating: a labour-hour basis charges overhead in proportion to
 * how many people touched the job, which misprices a capital-intensive shop badly. A
 * shop running its own machinery should switch. One that bills by the hour should not.
 */
class ProductionAbsorptionService
{
    public const WORK_IN_PROGRESS = '1350';

    public const MANUFACTURING_OVERHEAD = '1450';

    public const SALARIES_AND_WAGES = '6200';

    /**
     * Overhead as a share of direct labour hours.
     */
    public const BASIS_LABOUR_HOUR = 'labour_hour';

    /**
     * Overhead as each work centre's own recovery rate over the minutes it consumed.
     */
    public const BASIS_WORK_CENTRE_HOUR = 'work_centre_hour';

    public const ABSORPTION_BASES = [
        self::BASIS_LABOUR_HOUR,
        self::BASIS_WORK_CENTRE_HOUR,
    ];

    /**
     * The default basis.
     *
     * Labour hours, unchanged from when it was the only option. A default has to be
     * the behaviour existing users already have, or switching a setting nobody has
     * touched would silently restate their inventory.
     */
    public const DEFAULT_BASIS = self::BASIS_LABOUR_HOUR;

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
     * The overhead cost of one order, on the configured basis.
     *
     * ONE place computes it, because `absorbOverhead()` and `absorbedTotal()` both need
     * the figure and they must not be able to disagree: the first writes it to the
     * ledger, the second is what the completion posting relieves work in progress by. A
     * drift between them would leave WIP never returning to zero.
     *
     * `labour_hour` -- a single blended rate over recorded direct labour hours. Zero by
     * default rather than a guess: absorbing overhead nobody configured would invent
     * factory cost out of nothing and put it into inventory.
     *
     * `work_centre_hour` -- each centre's own `hourly_rate` over the minutes the job
     * consumed from it: recorded time where there is any, standard time otherwise. A
     * centre with no rate contributes nothing rather than defaulting to the global
     * rate: it was never costed, so guessing one would put invented cost into
     * inventory.
     */
    public function overheadFor(InventoryProductionOrder $order): string
    {
        return $this->basis() === self::BASIS_WORK_CENTRE_HOUR
            ? $this->workCentreOverheadFor($order)
            : $this->labourHourOverheadFor($order);
    }

    private function labourHourOverheadFor(InventoryProductionOrder $order): string
    {
        $rate = (float) Setting::get('production.overhead_rate_per_hour', 0);

        if ($rate <= 0.0) {
            return '0.00';
        }

        $minutes = (int) ProductionLabourEntry::query()
            ->where('production_order_id', $order->id)
            ->sum('minutes');

        if ($minutes <= 0) {
            return '0.00';
        }

        return Money::of(Money::multiply((string) $rate, (string) ($minutes / 60)));
    }

    private function workCentreOverheadFor(InventoryProductionOrder $order): string
    {
        $total = '0';

        $operations = InventoryProductionOrderOperation::query()
            ->where('production_order_id', $order->id)
            ->with('workCentre')
            ->get();

        foreach ($operations as $operation) {
            $centre = $operation->workCentre;

            if ($centre === null || (float) $centre->hourly_rate <= 0.0) {
                continue;
            }

            /**
             * Recorded time when there is any, standard time otherwise.
             *
             * Deliberately NOT the greater of the two, which is what the capacity report
             * uses. Capacity is a question about how loaded a centre is, and worst-case
             * is the honest answer to that. Absorption is a question about what a job
             * cost, and there the truth is the minutes the centre was actually occupied.
             *
             * Taking the greater would charge a job that ran in 30 minutes against a
             * 120-minute standard for 120 minutes of machine time -- inflating the cost
             * of the job that came in under, which is backwards, and burying the
             * favourable variance the shop most wants to see.
             *
             * With nothing recorded, the standard is the only figure there is. That is
             * not a claim the job took exactly that long; it is the plan until somebody
             * reports otherwise.
             */
            $minutes = $operation->isReported()
                ? (float) $operation->minutes_spent
                : (float) $operation->planned_minutes;

            if ($minutes <= 0.0) {
                continue;
            }

            $total = Money::add($total, $centre->costForMinutes($minutes));
        }

        return Money::of($total);
    }

    /**
     * The configured absorption basis.
     *
     * Unknown values fall back to the default rather than throwing. A typo in a setting
     * must not stop a production order completing; and because the fallback is the
     * previous behaviour, the cost lands where it always did.
     */
    public function basis(): string
    {
        $configured = (string) Setting::get('production.absorption_basis', self::DEFAULT_BASIS);

        return in_array($configured, self::ABSORPTION_BASES, true)
            ? $configured
            : self::DEFAULT_BASIS;
    }

    /**
     * Post the order's overhead into work in progress.
     */
    public function absorbOverhead(InventoryProductionOrder $order, ?string $date = null): ?JournalEntry
    {
        $overhead = $this->overheadFor($order);

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

        return Money::add(Money::of($labour), $this->overheadFor($order));
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
