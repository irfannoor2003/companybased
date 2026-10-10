<?php

namespace App\Console\Commands;

use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Mark invoices and bills past their due date as `overdue`.
 *
 * `overdue` has always been a valid status on `SalesInvoice` and `PurchaseInvoice`,
 * and the status filters across the dashboard and the reports have always included
 * it -- but nothing ever wrote it. Every document sat at `sent` indefinitely, so
 * the document itself and any report filtering on `status = 'overdue'` disagreed:
 * the dashboard counted overdue invoices from `due_date` (correct) while the
 * database said there were none (because the column was empty).
 *
 * Writing the status makes the two agree and puts the state on the record itself,
 * which is what a status column is for: a printed invoice can then read OVERDUE
 * rather than relying on a reader comparing the date by eye.
 *
 * Run hourly rather than daily so a document does not sit "on time" for up to a
 * day after its due date passes.
 */
class MarkOverdueInvoices extends Command
{
    protected $signature = 'accounting:mark-overdue';

    protected $description = 'Mark sales invoices and purchase bills past their due date as overdue';

    /**
     * Statuses that can become overdue.
     *
     * `paid` and `cancelled` are excluded: a settled document is not late, however
     * old its due date, and a cancelled one was never owed. `draft` is excluded
     * because a draft has not been issued and so has no due date to be late
     * against.
     *
     * @var array<int, string>
     */
    private const OPEN_STATUSES = ['sent', 'partially_paid'];

    public function handle(): int
    {
        $today = now()->toDateString();

        // One transaction per side so a failure part-way through cannot leave
        // sales marked and purchases unmarked.
        $sales = DB::transaction(fn () => $this->mark(SalesInvoice::query(), $today));

        $purchases = DB::transaction(fn () => $this->mark(PurchaseInvoice::query(), $today));

        $this->info(sprintf(
            'Marked %d sales invoice%s and %d purchase bill%s overdue.',
            $sales,
            $sales === 1 ? '' : 's',
            $purchases,
            $purchases === 1 ? '' : 's',
        ));

        return self::SUCCESS;
    }

    /**
     * Flip past-due open documents to `overdue`, leaving the rest alone.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\SalesInvoice|\App\Models\PurchaseInvoice>  $query
     */
    private function mark($query, string $today): int
    {
        return $query
            ->whereIn('status', self::OPEN_STATUSES)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', $today)
            ->update(['status' => 'overdue', 'updated_at' => now()]);
    }
}
