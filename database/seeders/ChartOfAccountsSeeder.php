<?php

namespace Database\Seeders;

use App\Models\Account;
use Illuminate\Database\Seeder;

/**
 * The default chart of accounts.
 *
 * This is structural configuration, not demo data: without it the trial
 * balance, balance sheet and profit & loss have no accounts to aggregate and
 * every accounting report renders blank on a fresh install. No journal entries
 * are created here — those are business data and stay empty per deployment.
 *
 * Idempotent: re-running only fills gaps and refreshes labels, so an operator
 * who has renamed or extended the chart keeps their edits.
 */
class ChartOfAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $definitions = [
            ['code' => '1000', 'name' => 'Cash & cash equivalents', 'type' => 'asset'],
            ['code' => '1100', 'name' => 'Accounts receivable', 'type' => 'asset', 'sub_type' => 'Current asset'],
            ['code' => '1200', 'name' => 'Inventory', 'type' => 'asset', 'sub_type' => 'Current asset'],
            ['code' => '1500', 'name' => 'Equipment', 'type' => 'asset', 'sub_type' => 'Fixed asset'],
            ['code' => '1600', 'name' => 'Accumulated depreciation', 'type' => 'asset', 'sub_type' => 'Contra asset'],
            ['code' => '1700', 'name' => 'Fixed assets', 'type' => 'asset', 'sub_type' => 'Fixed asset'],
            ['code' => '2000', 'name' => 'Accounts payable', 'type' => 'liability', 'sub_type' => 'Current liability'],
            ['code' => '2100', 'name' => 'Sales tax payable', 'type' => 'liability', 'sub_type' => 'Current liability'],
            ['code' => '2200', 'name' => 'Accrued expenses', 'type' => 'liability', 'sub_type' => 'Current liability'],
            ['code' => '2500', 'name' => 'Bank loans', 'type' => 'liability', 'sub_type' => 'Long term liability'],
            ['code' => '3000', 'name' => 'Retained earnings', 'type' => 'equity'],
            ['code' => '3100', 'name' => 'Owner contributions', 'type' => 'equity'],
            ['code' => '3200', 'name' => 'Owner drawings', 'type' => 'equity'],
            ['code' => '4000', 'name' => 'Sales revenue', 'type' => 'revenue'],
            ['code' => '4100', 'name' => 'Service revenue', 'type' => 'revenue'],
            ['code' => '4200', 'name' => 'Other income', 'type' => 'revenue'],
            ['code' => '5000', 'name' => 'Cost of goods sold', 'type' => 'expense'],
            ['code' => '5100', 'name' => 'Rent expense', 'type' => 'expense'],
            ['code' => '5200', 'name' => 'Utilities expense', 'type' => 'expense'],
            ['code' => '5300', 'name' => 'Office supplies', 'type' => 'expense'],
            ['code' => '5400', 'name' => 'Travel expense', 'type' => 'expense'],
            ['code' => '5500', 'name' => 'Software & subscriptions', 'type' => 'expense'],
            ['code' => '5600', 'name' => 'Depreciation expense', 'type' => 'expense'],
            ['code' => '5700', 'name' => 'Salaries & wages', 'type' => 'expense'],
            ['code' => '5800', 'name' => 'Bank charges', 'type' => 'expense'],
            ['code' => '5900', 'name' => 'Other expenses', 'type' => 'expense'],
        ];

        $currency = strtoupper((string) (settings('base_currency') ?: 'USD'));

        foreach ($definitions as $definition) {
            // Match trashed rows too: `code` is unique, so a soft-deleted
            // account must be revived rather than colliding on insert.
            $account = Account::withTrashed()->firstOrNew(['code' => $definition['code']]);

            $account->fill([
                'name' => $definition['name'],
                'type' => $definition['type'],
                'sub_type' => $definition['sub_type'] ?? null,
                'currency' => $currency,
                'is_active' => true,
            ]);

            if ($account->trashed()) {
                $account->restore();
            }

            $account->save();
        }
    }
}
