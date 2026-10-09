<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The approval request log.
 *
 * The application had no approval engine at all. Every module carried its own
 * ad-hoc status machine -- ExpenseClaim had reviewed_by and a pending/approved
 * pair, PurchaseOrder had confirmed, SalesOrder had confirmed -- and none of them
 * recorded who asked, who decided, when, or why. A business buying software asks
 * "can I control who approves what?" and the honest answer here was no.
 *
 * One row per decision, rather than columns on the document itself, for three
 * reasons:
 *
 *  - A single approval column pair cannot express a second reviewer, or a
 *    rejection followed by a resubmission.
 *  - Rejecting and resubmitting must not erase the previous refusal. A column
 *    that gets overwritten is not an audit trail.
 *  - The trail has to survive the document being deleted or the status changing,
 *    which is the whole point of keeping it separate.
 *
 * The partial unique index on (approvable, step) WHERE status = 'pending' is what
 * makes double-submission impossible. Two managers pressing submit at the same
 * moment would otherwise create two live requests for the same document, and the
 * document could then be approved twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();

            // Polymorphic: any document in any module can be submitted.
            $table->morphs('approvable');

            // Monotonic step, so a document can require more than one reviewer
            // (step 1 finance, step 2 director) without a bespoke table per flow.
            $table->unsignedInteger('step')->default(1);

            $table->string('status', 20)->default('pending');

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->nullable();

            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();

            // The reason for a refusal is the part an auditor asks for, so it is a
            // first-class field rather than an afterthought in a notes column.
            $table->text('comment')->nullable();

            $table->timestamps();

            $table->index(['approvable_type', 'approvable_id', 'status'], 'approval_requests_approvable_status_index');
        });

        /**
         * One live request per document per step.
         *
         * MySQL has no partial indexes, so the guarantee is expressed as a
         * generated column holding the step number only while the request is live,
         * and a unique index over it. A decided row yields NULL there, and MySQL
         * permits unlimited NULLs in a unique index -- which is exactly the
         * behaviour wanted: unlimited history, one live request.
         */
        Schema::table('approval_requests', function (Blueprint $table) {
            $table->unsignedInteger('live_step')
                ->nullable()
                ->storedAs("CASE WHEN `status` = 'pending' THEN `step` ELSE NULL END");

            $table->unique(
                ['approvable_type', 'approvable_id', 'live_step'],
                'approval_requests_one_live_per_step'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_requests');
    }
};