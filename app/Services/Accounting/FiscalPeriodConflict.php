<?php

namespace App\Services\Accounting;

/**
 * Two periods share at least one day.
 *
 * Expected business outcome, reported separately from a defect so the UI can
 * explain which period conflicts.
 */
class FiscalPeriodConflict extends \RuntimeException {}
