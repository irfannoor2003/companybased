<?php

namespace App\Console\Commands;

use App\Services\Accounting\YearEndClose;
use Illuminate\Console\Command;

/**
 * Close a fiscal year into retained earnings.
 *
 * Without this the balance sheet derived retained earnings as a plug
 * (`assets - liabilities - equity`), which meant it always agreed no matter what
 * the ledger contained. This makes the figure real, so a broken ledger shows up
 * as an unbalanced balance sheet.
 */
class CloseYearEnd extends Command
{
    protected $signature = 'accounting:close-year
                            {--until= : Close the year to this date (YYYY-MM-DD). Defaults to today.}';

    protected $description = 'Move the profit and loss into retained earnings for a closed year';

    public function handle(YearEndClose $close): int
    {
        $until = $this->option('until') ?: today()->toDateString();

        try {
            $entry = $close->close($until);
        } catch (\RuntimeException $e) {
            // A close that cannot run is an error, not a quiet no-op. Returning
            // success here would tell the operator the books are closed when
            // nothing was posted.
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($entry === null) {
            $this->warn("Nothing to close to {$until}: no profit and loss activity on or before that date, or the period is already closed.");

            return self::SUCCESS;
        }

        $this->info("Posted {$entry->number} - year-end close to {$until}.");

        $this->line('Retained earnings now reads what the business actually earned, rather than a balancing figure.');

        return self::SUCCESS;
    }
}
