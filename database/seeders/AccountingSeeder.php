<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Bill;
use App\Models\Budget;
use App\Models\ExpenseClaim;
use App\Models\JournalEntry;
use App\Models\JournalEntryItem;
use App\Models\TaxReturn;
use App\Support\GeneralLedger;
use Illuminate\Database\Seeder;

class AccountingSeeder extends Seeder
{
    /**
     * Demo accounting data: a chart of accounts, balanced journal entries,
     * expense claims, bills, a tax return and an operating budget. Money stays
     * in decimal strings — never floats.
     */
    public function run(): void
    {
        if (Account::exists()) {
            return;
        }

        $accounts = [
            ['code' => '1000', 'name' => 'Cash & cash equivalents', 'type' => 'asset'],
            ['code' => '1100', 'name' => 'Accounts receivable', 'type' => 'asset', 'sub_type' => 'Current asset'],
            ['code' => '1200', 'name' => 'Inventory', 'type' => 'asset', 'sub_type' => 'Current asset'],
            ['code' => '1500', 'name' => 'Equipment', 'type' => 'asset', 'sub_type' => 'Fixed asset'],
            ['code' => '1600', 'name' => 'Accumulated depreciation', 'type' => 'asset', 'sub_type' => 'Contra asset'],
            ['code' => '2000', 'name' => 'Accounts payable', 'type' => 'liability', 'sub_type' => 'Current liability'],
            ['code' => '2100', 'name' => 'Sales tax payable', 'type' => 'liability', 'sub_type' => 'Current liability'],
            ['code' => '2200', 'name' => 'Accrued expenses', 'type' => 'liability', 'sub_type' => 'Current liability'],
            ['code' => '3000', 'name' => 'Retained earnings', 'type' => 'equity'],
            ['code' => '3100', 'name' => 'Owner contributions', 'type' => 'equity'],
            ['code' => '4000', 'name' => 'Sales revenue', 'type' => 'revenue'],
            ['code' => '4100', 'name' => 'Service revenue', 'type' => 'revenue'],
            ['code' => '5000', 'name' => 'Cost of goods sold', 'type' => 'expense'],
            ['code' => '5100', 'name' => 'Rent expense', 'type' => 'expense'],
            ['code' => '5200', 'name' => 'Utilities expense', 'type' => 'expense'],
            ['code' => '5300', 'name' => 'Office supplies', 'type' => 'expense'],
            ['code' => '5400', 'name' => 'Travel expense', 'type' => 'expense'],
            ['code' => '5500', 'name' => 'Software & subscriptions', 'type' => 'expense'],
            ['code' => '5600', 'name' => 'Depreciation expense', 'type' => 'expense'],
            ['code' => '5700', 'name' => 'Other expenses', 'type' => 'expense'],
        ];

        $saved = [];

        foreach ($accounts as $definition) {
            $saved[$definition['code']] = Account::create(array_merge([
                'currency' => 'USD',
                'is_active' => true,
                'description' => null,
            ], $definition));
        }

        // Balanced journal: record revenue from cash sales.
        $revenueEntry = JournalEntry::create([
            'number' => next_document_number('journal_entry', 'JE'),
            'entry_date' => now()->subDays(20)->toDateString(),
            'reference' => 'MONTHLY-CLOSE',
            'description' => 'Cash sales for the period.',
            'status' => 'draft',
            'created_by' => 1,
        ]);

        GeneralLedger::replaceLines($revenueEntry, [
            ['account_id' => $saved['1000']->id, 'debit' => '15000.00', 'credit' => '0.00', 'memo' => 'Cash receipts'],
            ['account_id' => $saved['4000']->id, 'debit' => '0.00', 'credit' => '15000.00', 'memo' => 'Sales revenue'],
        ]);

        GeneralLedger::post($revenueEntry);

        // Balanced journal: pay rent out of the bank account.
        $rentEntry = JournalEntry::create([
            'number' => next_document_number('journal_entry', 'JE'),
            'entry_date' => now()->subDays(6)->toDateString(),
            'reference' => 'LANDLORD-06',
            'description' => 'Office rent for the current month.',
            'status' => 'draft',
            'created_by' => 1,
        ]);

        GeneralLedger::replaceLines($rentEntry, [
            ['account_id' => $saved['5100']->id, 'debit' => '2300.00', 'credit' => '0.00', 'memo' => 'Rent'],
            ['account_id' => $saved['1000']->id, 'debit' => '0.00', 'credit' => '2300.00', 'memo' => 'Bank payment'],
        ]);

        GeneralLedger::post($rentEntry);

        // Draft, unbalanced-looking but saved: utilities accrual.
        $draftEntry = JournalEntry::create([
            'number' => next_document_number('journal_entry', 'JE'),
            'entry_date' => now()->subDays(2)->toDateString(),
            'reference' => 'UTIL-08',
            'description' => 'Accrued utilities for the month.',
            'status' => 'draft',
            'created_by' => 1,
        ]);

        GeneralLedger::replaceLines($draftEntry, [
            ['account_id' => $saved['5200']->id, 'debit' => '410.00', 'credit' => '0.00', 'memo' => 'Electricity'],
            ['account_id' => $saved['2200']->id, 'debit' => '0.00', 'credit' => '410.00', 'memo' => 'Accrual'],
        ]);

        ExpenseClaim::create([
            'number' => next_document_number('expense_claim', 'EC'),
            'employee_name' => 'Ava Chen',
            'expense_date' => now()->subDays(9)->toDateString(),
            'expense_type' => 'travel',
            'merchant' => 'Delta Air',
            'amount' => '185.50',
            'currency' => 'USD',
            'status' => 'approved',
            'notes' => 'Client visit to Springfield.',
            'reviewed_by' => 1,
            'reviewed_at' => now()->subDays(7),
        ]);

        ExpenseClaim::create([
            'number' => next_document_number('expense_claim', 'EC'),
            'employee_name' => 'Ben Okoye',
            'expense_date' => now()->subDays(4)->toDateString(),
            'expense_type' => 'meals',
            'merchant' => 'Golden Fork',
            'amount' => '64.25',
            'currency' => 'USD',
            'status' => 'pending',
        ]);

        ExpenseClaim::create([
            'number' => next_document_number('expense_claim', 'EC'),
            'employee_name' => 'Ava Chen',
            'expense_date' => now()->subDays(15)->toDateString(),
            'expense_type' => 'software',
            'merchant' => 'DesignSuite Pro',
            'amount' => '49.00',
            'currency' => 'USD',
            'status' => 'reimbursed',
            'reviewed_by' => 1,
            'reviewed_at' => now()->subDays(12),
            'reimbursed_at' => now()->subDays(10),
        ]);

        $bill = Bill::create([
            'number' => next_document_number('bill', 'AP'),
            'vendor_name' => 'Northline Logistics',
            'supplier_id' => null,
            'bill_date' => now()->subDays(7)->toDateString(),
            'due_date' => now()->addDays(23)->toDateString(),
            'amount' => '1240.00',
            'paid_amount' => '0.00',
            'currency' => 'USD',
            'status' => 'open',
            'reference' => 'NLL-88231',
            'notes' => 'Freight for July shipments.',
        ]);

        $bill->items()->create(['account_id' => $saved['5000']->id, 'description' => 'Freight', 'amount' => '1240.00']);

        $paidBill = Bill::create([
            'number' => next_document_number('bill', 'AP'),
            'vendor_name' => 'Workspace Interiors',
            'supplier_id' => null,
            'bill_date' => now()->subDays(30)->toDateString(),
            'due_date' => now()->subDays(10)->toDateString(),
            'amount' => '850.00',
            'paid_amount' => '850.00',
            'currency' => 'USD',
            'status' => 'paid',
            'reference' => 'WI-5510',
            'notes' => 'Office furniture.',
        ]);

        $paidBill->items()->create(['account_id' => $saved['1500']->id, 'description' => 'Desks', 'amount' => '850.00']);

        TaxReturn::create([
            'number' => next_document_number('tax_return', 'TR'),
            'tax_type' => 'sales',
            'period_label' => 'Q2 2026',
            'period_start' => '2026-04-01',
            'period_end' => '2026-06-30',
            'gross_receipts' => '112000.00',
            'taxable_amount' => '96000.00',
            'tax_collected' => '3840.00',
            'tax_credits' => '1210.00',
            'tax_due' => '2630.00',
            'status' => 'paid',
            'filed_at' => '2026-07-18',
            'paid_at' => '2026-07-25',
            'currency' => 'USD',
            'notes' => 'Q2 sales tax filing.',
        ]);

        TaxReturn::create([
            'number' => next_document_number('tax_return', 'TR'),
            'tax_type' => 'sales',
            'period_label' => 'Q3 2026',
            'period_start' => '2026-07-01',
            'period_end' => '2026-09-30',
            'gross_receipts' => '0.00',
            'taxable_amount' => '0.00',
            'tax_collected' => '0.00',
            'tax_credits' => '0.00',
            'tax_due' => '0.00',
            'status' => 'draft',
            'currency' => 'USD',
            'notes' => 'Q3 sales tax filing.',
        ]);

        $budget = Budget::create([
            'name' => 'FY2026 Operating',
            'fiscal_year' => '2026',
            'currency' => 'USD',
            'description' => 'Annual operating budget for the current fiscal year.',
            'status' => 'active',
        ]);

        $budget->items()->create(['account_id' => $saved['4000']->id, 'budget_amount' => '180000.00']);
        $budget->items()->create(['account_id' => $saved['5000']->id, 'budget_amount' => '72000.00']);
        $budget->items()->create(['account_id' => $saved['5100']->id, 'budget_amount' => '24000.00']);
        $budget->items()->create(['account_id' => $saved['5200']->id, 'budget_amount' => '6000.00']);
        $budget->items()->create(['account_id' => $saved['5300']->id, 'budget_amount' => '3000.00']);
    }
}