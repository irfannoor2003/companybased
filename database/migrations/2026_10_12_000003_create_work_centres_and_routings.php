<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Work centres, and routings that say which centre makes what, in what order.
 *
 * There was no work centre, machine or equipment record anywhere in this
 * application, and ProductionAbsorptionService said so in a comment: overhead was
 * absorbed on direct labour hours because a machine-hour rate would have had nothing
 * to measure against. That comment is now out of date, and the limitation it
 * described is removed rather than documented around.
 *
 * A work centre is a place work happens and the thing that constrains it: a machine,
 * a bench, a person, a line. It carries its own rate and its available minutes per
 * day, which is what turns "the job took some time" into a schedule.
 *
 * A routing is the SEQUENCE of operations a product goes through, attached to its
 * bill of materials, because the operations belong to the recipe: changing the
 * recipe changes how the thing is made. Each operation names its work centre, its
 * order, and a standard time.
 *
 *     standard minutes = setup_minutes + (run_minutes_per_unit x quantity)
 *
 * Setup is a fixed cost of getting the centre ready regardless of batch size, and
 * run time scales with it. A 10-unit batch and a 1,000-unit batch of the same
 * product have very different times on the same centre, and a routing that could not
 * express that would be useless for planning.
 *
 * Production orders carry the operations actually performed, not just the planned
 * ones. `minutes_spent` is recorded per operation, which is what makes the standard
 * time above a comparison rather than an assumption -- and what lets overhead be
 * absorbed on machine hours once there is something to measure them against.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Three independent tables: guard each create so a host that ran part
        // of this migration outside the ledger skips only what it already has.
        // (The restored live schema on some deployments has the tables but no
        // migrations row for this file, and DDL is not transactional.)
        if (! Schema::hasTable('inventory_work_centres')) {
            Schema::create('inventory_work_centres', function (Blueprint $table) {
                $table->id();
                $table->string('code')->unique();
                $table->string('name');

                // What the centre IS, stated rather than implied by a free-text name.
                // It changes what a rate means: a machine hour and a labour hour are
                // different units, and conflating them is how a capacity plan becomes
                // meaningless.
                $table->string('type')->default('machine');   // machine | labour | assembly | outsourced

                $table->decimal('hourly_rate', 14, 4)->default(0)
                    ->comment('Recovery rate per hour, for costing this centre.');

                $table->unsignedInteger('capacity_minutes_per_day')->nullable()
                    ->comment('Available minutes per working day. Null means unconstrained.');

                $table->string('location')->nullable();
                $table->text('notes')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['type', 'is_active']);
            });
        }

        if (! Schema::hasTable('inventory_bom_operations')) {
            Schema::create('inventory_bom_operations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('bill_of_material_id')
                    ->constrained('inventory_bill_of_materials')->cascadeOnDelete();
                $table->foreignId('work_centre_id')
                    ->constrained('inventory_work_centres')->restrictOnDelete();

                // Sequence, 1-based. Order matters: operations are performed in order and
                // the routing is meaningless without it.
                $table->unsignedInteger('sequence');

                $table->string('name')->nullable()
                    ->comment('Defaults to the work centre name when empty.');

                $table->decimal('setup_minutes', 10, 2)->default(0);
                $table->decimal('run_minutes_per_unit', 10, 4)->default(0);

                $table->text('notes')->nullable();
                $table->timestamps();

                $table->unique(['bill_of_material_id', 'sequence']);
                $table->index('work_centre_id');
            });
        }

        if (! Schema::hasTable('inventory_production_order_operations')) {
            Schema::create('inventory_production_order_operations', function (Blueprint $table) {
                $table->id();

                /**
                 * Constraint names are given explicitly because Laravel's generated ones
                 * exceed MySQL's 64-character identifier limit here:
                 * `inventory_production_order_operations_production_order_id_foreign`
                 * is 68 characters, and the migration fails on it with error 1059.
                 */
                $table->unsignedBigInteger('production_order_id');
                $table->unsignedBigInteger('bom_operation_id')->nullable();
                $table->unsignedInteger('sequence');
                $table->string('name');
                $table->unsignedBigInteger('work_centre_id');

                /**
                 * Constraint names are given explicitly because Laravel's generated ones
                 * exceed MySQL's 64-character identifier limit here:
                 * `inventory_production_order_operations_production_order_id_foreign`
                 * is 68 characters and the migration fails on it with error 1059.
                 */
                $table->foreign('production_order_id', 'poo_production_order_fk')
                    ->references('id')->on('inventory_production_orders')->cascadeOnDelete();
                $table->foreign('bom_operation_id', 'poo_bom_operation_fk')
                    ->references('id')->on('inventory_bom_operations')->nullOnDelete();
                $table->foreign('work_centre_id', 'poo_work_centre_fk')
                    ->references('id')->on('inventory_work_centres')->restrictOnDelete();

                $table->decimal('setup_minutes', 10, 2)->default(0);
                $table->decimal('run_minutes_per_unit', 10, 4)->default(0);

                $table->decimal('planned_minutes', 12, 2)->default(0);
                $table->decimal('minutes_spent', 12, 2)->default(0)
                    ->comment('Actual. Zero means not yet reported.');

                // Denormalised deliberately. A production order keeps showing the route
                // it was RELEASED against, not whatever the recipe says today: editing a
                // BOM after a job has started must not silently retime work already
                // half done.
                $table->string('status')->default('pending');   // pending | running | complete
                $table->dateTime('started_at')->nullable();
                $table->dateTime('completed_at')->nullable();
                $table->timestamps();

                $table->index(['production_order_id', 'sequence'], 'poo_order_sequence_index');
                $table->index(['work_centre_id', 'status'], 'poo_centre_status_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_production_order_operations');
        Schema::dropIfExists('inventory_bom_operations');
        Schema::dropIfExists('inventory_work_centres');
    }
};
