<?php

namespace App\Services\Accounting;

/**
 * A ledger posting refused for a business reason the operator can act on.
 *
 * Distinct from a plain RuntimeException on purpose. Controllers that wrap a save
 * in a transaction commonly catch RuntimeException and render it as a danger toast,
 * which is right for "you overpaid this bill" and wrong for "the journal did not
 * balance". The second is a defect, and it must reach the log rather than being
 * presented to a user as if they had done something wrong -- and, worse, must not
 * quietly roll the transaction back and look like an ordinary validation failure.
 */
class LedgerPostingFailure extends \RuntimeException
{
}