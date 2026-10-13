<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One operation in a routing: a step in making a product, at a work centre.
 *
 * It hangs off the BILL OF MATERIALS rather than the product, because the operations
 * belong to the recipe. Changing how a thing is made is a change of recipe, and a
 * separate "routing" table would let the two drift apart -- the same product then
 * having a bill of materials that describes its parts and a routing that describes
 * a process nobody can actually make it by.
 *
 *     standard minutes = setup_minutes + (run_minutes_per_unit x quantity)
 *
 * Setup is a fixed cost of getting a centre ready regardless of batch size, and run
 * time scales with it. A 10-unit and a 1,000-unit batch of the same product take very
 * different times on the same centre; a routing that could not express that would be
 * no use for planning.
 */
class InventoryBomOperation extends Model
{
    protected $fillable = [
        'bill_of_material_id', 'work_centre_id', 'sequence', 'name',
        'setup_minutes', 'run_minutes_per_unit', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'setup_minutes' => 'decimal:2',
            'run_minutes_per_unit' => 'decimal:4',
        ];
    }

    public function billOfMaterial(): BelongsTo
    {
        return $this->belongsTo(InventoryBillOfMaterial::class);
    }

    public function workCentre(): BelongsTo
    {
        return $this->belongsTo(InventoryWorkCentre::class);
    }

    public function orderOperations(): HasMany
    {
        return $this->hasMany(InventoryProductionOrderOperation::class, 'bom_operation_id');
    }

    /**
     * Standard minutes for a batch of $quantity units.
     */
    public function standardMinutes(float $quantity): float
    {
        return round((float) $this->setup_minutes + ((float) $this->run_minutes_per_unit * $quantity), 2);
    }
}