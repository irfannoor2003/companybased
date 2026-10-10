<?php

namespace Database\Seeders;

use App\Models\Account;
use Illuminate\Database\Seeder;

/**
 * A working default chart of accounts.
 *
 * The ledger previously shipped empty: nothing in the application generates journal
 * entries, and no account rows existed either, so the accounting module could only
 * ever show a blank trial balance and a P&L of zero.
 *
 * These are the accounts the automatic posting rules in
 * App\Services\Accounting\LedgerPosting reference by code. They follow the standard
 * numbering an accountant expects (1xxx assets, 2xxx liabilities, 3xxx equity,
 * 4xxx revenue, 5xxx/6xxx expenses), so a deployment can rename them freely -- but
 * changing a *code* breaks the posting rules, so prefer adding your own accounts
 * and remapping rather than renumbering these.
 *
 * Deliberately not seeded per-customer in the sense of being final: every deployment
 * should have its accountant review these before real books are kept against them.
 */
class ChartOfAccountsSeeder extends Seeder
{
    /**
     * code => [name, type, sub_type, description]
     *
     * @var array<string, array{0: string, 1: string, 2: string|null, 3: string}>
     */
    private const ACCOUNTS = [
        // ── Assets ────────────────────────────────────────────────────────────
        '1000' => ['Cash on Hand', 'asset', 'current', 'Physical petty cash and till float.'],
        '1010' => ['Bank', 'asset', 'current', 'Cheque and saving accounts.'],
        '1100' => ['Accounts Receivable', 'asset', 'current', 'Amounts owed by customers on invoices.'],
        '1200' => ['Inventory', 'asset', 'current', 'Stock on hand at cost.'],
        '1300' => ['Prepaid Expenses', 'asset', 'current', 'Paid costs not yet consumed.'],
        '1310' => ['Input Tax Receivable', 'asset', 'current', 'Purchase tax paid by the business, recoverable from the authority.'],

        // ── Liabilities ────────────────────────────────────────────────────────
        '2000' => ['Accounts Payable', 'liability', 'current', 'Amounts owed to suppliers on bills.'],
        '2100' => ['Accrued Liabilities', 'liability', 'current', 'Costs incurred but not yet invoiced.'],
'2200' => ['Output Tax Payable', 'liability', 'current', 'Sales tax collected, owed to the authority.'],
  '2250' => ['Payroll Tax Payable', 'liability', 'current', 'Employee tax and social contributions withheld from net pay, owed to the authority.'],
  '2300' => ['Withholding Tax Payable', 'liability', 'current', 'Tax withheld from customers, owed to the authority.'],
  '2150' => ['Net Pay Payable', 'liability', 'current', 'Gross pay earned by employees but not yet paid out.'],

        // ── Equity ─────────────────────────────────────────────────────────────
        '3000' => ['Owner Equity', 'equity', 'capital', 'Capital contributed by owners.'],
        '3100' => ['Retained Earnings', 'equity', 'retained', 'Accumulated profit not distributed.'],

        // ── Revenue ────────────────────────────────────────────────────────────
        '4000' => ['Sales Revenue', 'revenue', 'operating', 'Goods and services invoiced to customers.'],
        '4100' => ['Other Income', 'revenue', 'other', 'Interest, discounts and incidental income.'],
          '4200' => ['Sales Discounts', 'revenue', 'contra', 'Discounts and price reductions given to customers. Contra-revenue.'],
        '4300' => ['Sales Returns', 'revenue', 'contra', 'Goods and services credited back to customers. Contra-revenue: a credit note records that part of a sale came back rather than erasing it, so gross invoiced revenue stays intact for period-on-period comparison.'],

        // ── Expenses ───────────────────────────────────────────────────────────
        '5000' => ['Cost of Goods Sold', 'expense', 'cost', 'Direct cost of goods sold.'],
        '6000' => ['Operating Expenses', 'expense', 'operating', 'General running costs.'],
        '6100' => ['Rent and Utilities', 'expense', 'operating', 'Premises and utilities.'],
        '6200' => ['Salaries and Wages', 'expense', 'operating', 'Payroll cost.'],
        '6300' => ['Bank Charges', 'expense', 'operating', 'Bank fees and merchant charges.'],
  '6600' => ['Unrealised Exchange Gain', 'revenue', 'other', 'Gain on revaluing foreign currency balances at a closing rate.'],
  '6610' => ['Unrealised Exchange Loss', 'expense', 'operating', 'Loss on revaluing foreign currency balances at a closing rate.'],
  '2050' => ['Uncategorised Bank Items', 'liability', 'current', 'Deposits and withdrawals not yet identified. Cleared once matched.'],
        '6400' => ['Inventory Adjustment', 'expense', 'cost', 'Write-offs and stock count variance.'],
  '1350' => ['Work in Progress', 'asset', 'current', 'Components consumed by production but not yet transferred to finished goods.'],
  '1450' => ['Manufacturing Overhead', 'expense', 'cost', 'Factory overhead control: actual overhead debited here, overhead absorbed into work in progress credited here. A residual balance is overhead not yet absorbed and belongs in the period cost.'],
  '6700' => ['Production Variance', 'expense', 'cost', 'Difference between the cost of components consumed and the standard cost of goods produced.'],
    ];

    public function run(): void
    {
        // Read defensively rather than through the settings() helper: this runs
        // inside DatabaseSeeder, where a missing or half-written settings row must
        // not abort the whole seed and leave the ledger empty.
        $currency = 'USD';

        try {
            $currency = (string) (\App\Models\Setting::get('company.currency') ?: 'USD');
        } catch (\Throwable) {
            // Fall through to the default.
        }

        foreach (self::ACCOUNTS as $code => [$name, $type, $subType, $description]) {
            Account::updateOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'type' => $type,
                    'sub_type' => $subType,
                    'currency' => $currency,
                    'is_active' => true,
                    'description' => $description,
                ],
            );
        }

        $this->seedTaxRates();
    }

    /**
     * Give the ledger a tax catalogue to work from.
     *
     * The permission for managing tax rates existed with nothing behind it, so every
     * document line carried a hand-typed percentage. Seeding a single zero-rate
     * placeholder would be worse than seeding none: a zero rate looks like a
     * deliberate exemption, and TaxCalculator reports a missing rate as an
     * administrative gap. Leaving the table empty keeps that distinction honest --
     * the user sets their real rates once, and until then every document reports
     * that no rate is configured rather than quietly charging nothing.
     */
    private function seedTaxRates(): void
    {
        if (\App\Models\TaxRate::query()->exists()) {
            return;
        }

        // Nothing seeded deliberately. See the method docblock.
    }

    /**
     * The codes the automatic posting rules depend on.
     *
     * @return array<string, string>
     */
    public static function codes(): array
    {
        return array_map(fn (array $a): string => $a[0], self::ACCOUNTS);
    }
}
