<?php

namespace App\Services\Accounting;

use Illuminate\Support\Carbon;

/**
 * A journal entry was refused because its date falls in a period that will not
 * accept it.
 *
 * This is an expected, explainable business outcome -- the books are closed --
 * not a defect. It is separated from LedgerPostingFailure so the UI can say
 * "the period is locked" rather than reporting a system fault.
 */
class FiscalPeriodClosed extends \RuntimeException
{
    public static function forDate(mixed $date): self
    {
        return new self('Cannot post a journal dated '.Carbon::parse($date)->format('Y-m-d').'.');
    }
}
