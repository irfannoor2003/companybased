<?php

namespace App\Services\Accounting;

/**
 * A locked period was asked to change. Terminal by design: locked figures have
 * been reported, so the only correction is a reversing entry.
 */
class FiscalPeriodLocked extends \RuntimeException {}
