<?php

namespace App\Console\Commands;

use App\Services\LowStockService;
use Illuminate\Console\Command;

class CheckLowStock extends Command
{
    protected $signature = 'inventory:low-stock';

    protected $description = 'Alert inventory managers about items at or below their reorder level';

    public function handle(LowStockService $lowStock): int
    {
        $alerted = $lowStock->scanAll();

        $this->info(sprintf(
            'Checked inventory. %d item%s below reorder level.',
            count($alerted),
            count($alerted) === 1 ? '' : 's',
        ));

        if ($alerted !== []) {
            $this->line('Alerted: '.implode(', ', $alerted));
        }

        return self::SUCCESS;
    }
}
