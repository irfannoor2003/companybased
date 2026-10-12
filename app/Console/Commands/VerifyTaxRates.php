<?php

namespace App\Console\Commands;

use App\Services\Accounting\LedgerPosting;
use Illuminate\Console\Command;

/**
 * Report tax rates whose ledger account does not match where their tax posts.
 *
 * `tax_rates.account_id` reads as though it decides where a rate's tax lands in the
 * ledger. It does not -- posting has always used the fixed OUTPUT_TAX_PAYABLE and
 * INPUT_TAX_RECEIVABLE accounts, and no document item table carries `tax_rate_id`
 * to identify the rate behind a posted figure.
 *
 * That makes the column a trap rather than a control: an owner who sets a sales
 * rate's account to the input-tax account would see it saved, and the tax would
 * still post to output tax, with nothing anywhere reporting the disagreement.
 *
 * So the column is checked against the truth instead of trusted. A NULL or
 * mismatched account is reported, not silently corrected -- the account mapping is
 * the owner's to confirm.
 */
class VerifyTaxRates extends Command
{
    protected $signature = 'accounting:verify-tax-rates
                            {--fix : Point each rate at the account its scope actually posts to}';

    protected $description = 'Check that each tax rate names the ledger account its tax posts to';

    public function handle(): int
    {
        $rates = \App\Models\TaxRate::query()->where('is_active', true)->get();

        if ($rates->isEmpty()) {
            $this->warn('No active tax rates are configured. Documents with tax will report a missing rate.');

            return self::SUCCESS;
        }

        $problems = 0;

        foreach ($rates as $rate) {
            $expected = LedgerPosting::taxAccountFor($rate->applies_to);
            $expectedId = \App\Models\Account::where('code', $expected)->value('id');

            if ($expectedId === null) {
                $this->error("{$rate->code}: account {$expected} is missing from the chart of accounts.");

                $problems++;

                continue;
            }

            if ((int) $rate->account_id === (int) $expectedId) {
                continue;
            }

            $problems++;
            $current = $rate->account_id
                ? \App\Models\Account::find($rate->account_id)?->code.' '.\App\Models\Account::find($rate->account_id)?->name
                : 'none';

            $this->line(sprintf(
                '<fg=yellow>%s</> (%s, %s%%): names %s, but its tax posts to %s %s',
                $rate->code,
                $rate->applies_to,
                rtrim(rtrim(number_format((float) $rate->rate, 4, '.', ''), '0'), '.'),
                $current,
                $expected,
                \App\Models\Account::find($expectedId)?->name ?? '',
            ));

            if ($this->option('fix')) {
                $rate->forceFill(['account_id' => $expectedId])->save();
                $this->line("   <fg=green>set to {$expected}</>");
            }
        }

        if ($problems === 0) {
            $this->info('Every active tax rate names the account its tax posts to.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->error("{$problems} rate(s) do not name the account their tax posts to.");

        if (! $this->option('fix')) {
            $this->line('Re-run with <info>--fix</info> to point them at the right account.');
        }

        return self::FAILURE;
    }
}
