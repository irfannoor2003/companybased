<?php

namespace App\Services\Inventory;

use App\Models\InventoryBillOfMaterial;
use App\Models\InventoryItem;
use App\Services\Accounting\ProductionPostingService;
use Illuminate\Support\Collection;

/**
 * Explode a bill of materials down to its purchasable leaves.
 *
 * A BOM could already EXPRESS a sub-assembly -- a component line points at an
 * inventory item, and that item can be the subject of a BOM of its own -- but
 * nothing ever read that second level. Every explosion was a single pass over the
 * top-level lines, so a product built from a sub-assembly that was itself built
 * from three parts would plan to buy the sub-assembly and stop there: a purchasing
 * requirement that could not be turned into a purchase order, because no supplier
 * sells an unbuilt sub-assembly.
 *
 * The recursion is the whole feature, and the reason it needs a hard guard:
 *
 * A BOM may legitimately contain itself by accident, and a data-entry mistake is
 * the likeliest way to get one -- A requires B, B requires A. Without a cycle guard
 * that is an unbounded recursion: it does not return an error, it exhausts memory
 * and takes the request process with it. So the chain of items already being
 * expanded is carried down, and a repeat is reported rather than followed.
 *
 * A cycle is reported, not silently truncated, because the two are different: a
 * self-referencing BOM is a data error the owner must fix, and quietly returning
 * the branches that happened to work would let a broken structure look fine.
 */
class BomExplosionService
{
    /**
     * Backstop on depth, independent of the cycle guard.
     *
     * A cycle guard catches the realistic mistakes. This catches the rest -- a
     * chain 40 sub-assemblies deep is legal data but is almost certainly not what
     * anyone meant, and recursing through it costs a query per level.
     */
    private const MAX_DEPTH = 25;

    /**
     * @var array<int, string>  human-readable descriptions of any cycles found
     */
    private array $cycles = [];

    /**
     * @return array<int, string>
     */
    public function cycles(): array
    {
        return $this->cycles;
    }

    /**
     * Flatten a BOM into leaf components for a quantity of finished output.
     *
     * @return array<int, array{item: InventoryItem, bom_item: mixed, level: int, path: string, gross: float}>
     */
    public function explode(InventoryBillOfMaterial $bom, float $quantity): array
    {
        $this->cycles = [];

        return $this->walk($bom, $quantity, 1, [], (string) $bom->item_id);
    }

    /**
     * Depth-first expansion of one BOM level.
     *
     * @param  array<int, int>  $chain  item ids currently being expanded
     * @return array<int, array{item: InventoryItem, bom_item: mixed, level: int, path: string, gross: float}>
     */
    private function walk(InventoryBillOfMaterial $bom, float $quantity, int $level, array $chain, string $path): array
    {
        if ($level > self::MAX_DEPTH) {
            $this->cycles[] = sprintf('%s: nesting deeper than %d levels', $path, self::MAX_DEPTH);

            return [];
        }

        $lines = [];

        foreach ($bom->items()->with('componentItem.product')->get() as $bomItem) {
            $component = $bomItem->componentItem;

            if ($component === null) {
                continue;
            }

            // Wastage multiplies the requirement at EVERY level, not just the top.
            // A sub-assembly is built with its own loss, and ignoring that here
            // under-orders whatever that sub-assembly is made of.
            $wastageFactor = 1 + ((float) $bomItem->wastage_percent / 100);
            $gross = $quantity * (float) $bomItem->quantity * $wastageFactor;

            $childPath = $path.' > '.($component->product?->name ?? 'item#'.$component->id);

            // The cycle check, before descending.
            if (in_array((int) $component->id, $chain, true)) {
                $this->cycles[] = sprintf('%s: component appears in its own recipe', $childPath);

                continue;
            }

            $child = $this->activeBomFor($component);

            if ($child !== null) {
                // A sub-assembly: expand it and fold the result in, so the output is
                // only ever the parts that can actually be bought or built.
                $nested = $this->walk(
                    $child,
                    $gross,
                    $level + 1,
                    [...$chain, (int) $component->id],
                    $childPath,
                );

                foreach ($nested as $line) {
                    $lines[] = $line;
                }

                continue;
            }

            $lines[] = [
                'item' => $component,
                'bom_item' => $bomItem,
                'level' => $level,
                'path' => $childPath,
                'gross' => round($gross, 3),
            ];
        }

        return $lines;
    }

    /**
     * The active BOM for a component, if it has one and it makes sense.
     *
     * `whereNotNull('item_id')` because an item with a BOM but no subject item is
     * unusable as a sub-assembly.
     */
    private function activeBomFor(InventoryItem $item): ?InventoryBillOfMaterial
    {
        if ($item->bill_of_material_id && $item->relationLoaded('billOfMaterial') && $item->billOfMaterial) {
            return $item->billOfMaterial;
        }

        return InventoryBillOfMaterial::query()
            ->where('item_id', $item->id)
            ->where('status', 'active')
            ->orderByDesc('version')
            ->orderByDesc('id')
            ->first();
    }
}