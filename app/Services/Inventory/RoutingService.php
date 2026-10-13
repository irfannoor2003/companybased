<?php

namespace App\Services\Inventory;

use App\Models\InventoryBillOfMaterial;
use App\Models\InventoryProductionOrder;
use App\Models\InventoryProductionOrderOperation;
use App\Models\InventoryWorkCentre;

/**
 * Release a routing onto a production order, and report on the work centres.
 *
 * Releasing COPIES the routing onto the order rather than referencing it, which is
 * the whole point: a job released last week must not be re-timed because somebody
 * edited the bill of materials this morning. Work already half done cannot have its
 * standard times rewritten underneath it.
 *
 * Existing operations are left alone once reported. Re-releasing an order overwrites
 * the PLANNED side of unreported operations only -- so a supervisor correcting the
 * sequence of a job nobody has started on works, while recorded time is never
 * silently discarded.
 */
class RoutingService
{
    /**
     * Copy a BOM's routing onto a production order.
     *
     * @return array{released: int, skipped: int}
     */
    public function release(InventoryProductionOrder $order, InventoryBillOfMaterial $bom): array
    {
        $operations = $bom->operations()
            ->with('workCentre')
            ->orderBy('sequence')
            ->get();

        $released = 0;
        $skipped = 0;

        foreach ($operations as $operation) {
            $workCentre = $operation->workCentre;

            if ($workCentre === null) {
                $skipped++;

                continue;
            }

            /**
             * A retired work centre cannot take work, so its operation is not
             * released. Skipping it silently would leave the order claiming a route
             * that does not exist; the caller is told the count.
             */
            if (! $workCentre->is_active) {
                $skipped++;

                continue;
            }

            $planned = $operation->standardMinutes((float) $order->quantity);

            $existing = InventoryProductionOrderOperation::query()
                ->where('production_order_id', $order->id)
                ->where('bom_operation_id', $operation->id)
                ->first();

            if ($existing !== null) {
                // Recorded time is never overwritten.
                if ($existing->isReported() || $existing->status === 'complete') {
                    $skipped++;

                    continue;
                }

                $existing->forceFill([
                    'sequence' => $operation->sequence,
                    'name' => $operation->name ?: $workCentre->name,
                    'work_centre_id' => $workCentre->id,
                    'setup_minutes' => $operation->setup_minutes,
                    'run_minutes_per_unit' => $operation->run_minutes_per_unit,
                    'planned_minutes' => $planned,
                ])->save();

                $released++;

                continue;
            }

            InventoryProductionOrderOperation::create([
                'production_order_id' => $order->id,
                'bom_operation_id' => $operation->id,
                'sequence' => $operation->sequence,
                'name' => $operation->name ?: $workCentre->name,
                'work_centre_id' => $workCentre->id,
                'setup_minutes' => $operation->setup_minutes,
                'run_minutes_per_unit' => $operation->run_minutes_per_unit,
                'planned_minutes' => $planned,
                'minutes_spent' => 0,
                'status' => 'pending',
            ]);

            $released++;
        }

        return ['released' => $released, 'skipped' => $skipped];
    }

    /**
     * Release from whatever BOM the order is linked to, if any.
     *
     * @return array{released: int, skipped: int}
     */
    public function releaseFromOrderBom(InventoryProductionOrder $order): array
    {
        $bom = $order->billOfMaterial ?? $order->bill_of_material_id
            ? InventoryBillOfMaterial::find($order->bill_of_material_id)
            : null;

        if ($bom === null) {
            return ['released' => 0, 'skipped' => 0];
        }

        return $this->release($order, $bom);
    }

    /**
     * Record time against an operation.
     *
     * Reported minutes are KEPT, not replaced, and the running total is added
     * instead. A supervisor who clocks an operation in, then tops up the time because
     * the job ran long, expects the total. Overwriting would silently discard the
     * first entry -- and since the first entry is the one somebody will have
     * written down on paper, that is the worse of the two mistakes.
     */
    public function recordMinutes(InventoryProductionOrderOperation $operation, float $minutes): void
    {
        $total = (float) $operation->minutes_spent + max(0.0, $minutes);

        $operation->forceFill([
            'minutes_spent' => $total,
            'status' => 'complete',
            'started_at' => $operation->started_at ?? now(),
            'completed_at' => now(),
        ])->save();
    }

    /**
     * Work centre load for a day, worst first.
     *
     * @return array<int, array{work_centre: InventoryWorkCentre, capacity: int|null, committed: float, remaining: float|null, over_capacity: bool, operations: int}>
     */
    public function capacityFor(\DateTimeInterface|string $date): array
    {
        $day = \Illuminate\Support\Carbon::parse($date)->toDateString();

        $rows = [];

        foreach (InventoryWorkCentre::where('is_active', true)->orderBy('code')->get() as $centre) {
            $load = $centre->capacityFor($day);

            $rows[] = [
                'work_centre' => $centre,
                'capacity' => $load['capacity'],
                'committed' => $load['committed'],
                'remaining' => $load['remaining'],
                'over_capacity' => $load['over_capacity'],
                'operations' => InventoryProductionOrderOperation::query()
                    ->where('work_centre_id', $centre->id)
                    ->whereHas('productionOrder', fn ($q) => $q->whereDate('scheduled_end_date', $day))
                    ->count(),
            ];
        }

        usort($rows, fn ($a, $b) => [$b['over_capacity'], $b['committed']] <=> [$a['over_capacity'], $a['committed']]);

        return $rows;
    }
}