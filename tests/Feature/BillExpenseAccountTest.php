<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\PurchaseInvoice;
use App\Models\TaxRate;
use App\Services\Accounting\LedgerPosting;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * Every purchase bill posted its whole net value to Cost of Goods Sold, so the
 * profit and loss statement could not answer the only question anyone asks of it:
 * which expenses went up. Rent, utilities and salary all landed on one line.
 *
 * Bill lines now carry the expense account they are charged to, and the posting
 * rule groups by it. These tests prove the breakout, the fallback when no account
 * is chosen, that the entry still balances after splitting, and that the tax and
 * the payable are unaffected by the split.
 */
class BillExpenseAccountTest extends TestCase
{
    use SeedsDatabase;

    private ?\App\Models\User $admin = null;

    private function admin(): \App\Models\User
    {
        return $this->admin ??= $this->userForRole('Admin');
    }

    private function ledger(): LedgerPosting
    {
        return new LedgerPosting();
    }

    private function code(string $code): Account
    {
        return Account::where('code', $code)->firstOrFail();
    }

    /**
     * @param  array<int, array{description: string, price: float, tax_percent?: float, account?: Account|null}>  $lines
     */
private function postBill(array $lines): PurchaseInvoice
    {
        $supplier = $this->supplier();

        /**
         * The tax engine refuses a line that charges tax with no configured rate
         * behind it, so any bill charging tax needs a real rate on file first.
         * Intended behaviour: posting 17% to the ledger with nothing defining which
         * rate it was makes the input-tax return irreproducible.
         */
        if (collect($lines)->contains(fn ($line) => (float) ($line['tax_percent'] ?? 0) > 0)) {
            TaxRate::firstOrCreate(
                ['code' => 'BILL-17'],
                [
                    'name' => 'Standard VAT',
                    'rate' => '17.0000',
                    'applies_to' => 'purchase',
                    'effective_from' => now()->subYear()->startOfYear()->toDateString(),
                    'is_active' => true,
                    'account_id' => Account::where('code', '2200')->value('id'),
                ],
            );
        }

        $items = [];

        foreach ($lines as $line) {
            $items[] = [
                'description' => $line['description'],
                'qty' => 1,
                'unit_price' => (string) $line['price'],
                'discount_percent' => 0,
                'tax_percent' => (string) ($line['tax_percent'] ?? 0),
                'account_id' => ($line['account'] ?? null)?->id,
            ];
        }

        $this->actingAs($this->admin())
            ->post('/suppliers/purchase-invoices', [
                'supplier_id' => $supplier->id,
                'issue_date' => now()->toDateString(),
                'status' => 'draft',
                'currency' => 'USD',
                'items' => $items,
            ])
            ->assertSessionHasNoErrors();

        return PurchaseInvoice::where('supplier_id', $supplier->id)->latest('id')->firstOrFail();
    }

    public function test_the_seeder_provides_expense_accounts_to_charge_lines_to(): void
    {
        $expenses = Account::where('type', 'expense')->pluck('code')->all();

        $this->assertContains('5000', $expenses, 'Cost of Goods Sold');
        $this->assertContains('6100', $expenses, 'Rent and Utilities');
        $this->assertContains('6200', $expenses, 'Salaries and Wages');
        $this->assertContains('6300', $expenses, 'Bank Charges');
    }

    public function test_lines_are_charged_to_their_own_expense_accounts(): void
    {
        $bill = $this->postBill([
            ['description' => 'Goods', 'price' => 400.00, 'account' => $this->code('5000')],
            ['description' => 'August rent', 'price' => 250.00, 'account' => $this->code('6100')],
            ['description' => 'August salary', 'price' => 900.00, 'account' => $this->code('6200')],
        ]);

        $this->assertSame(400.00, (float) $this->code('5000')->balance(), 'goods go to COGS');
        $this->assertSame(250.00, (float) $this->code('6100')->balance(), 'rent goes to rent and utilities');
        $this->assertSame(900.00, (float) $this->code('6200')->balance(), 'salary goes to salaries and wages');

        // The split must still balance, and the payable must be the gross.
        $entry = $this->ledger()->journalFor($bill);

        $this->assertSame('posted', $entry->status);

        $debits = '0';
        $credits = '0';

        foreach ($entry->load('items')->items as $item) {
            $debits = \App\Support\Money::add($debits, $item->debit);
            $credits = \App\Support\Money::add($credits, $item->credit);
        }

        $this->assertSame($debits, $credits, 'splitting by account must not unbalance the entry');
        $this->assertSame('1550.00', $debits);
        $this->assertSame(-1550.00, (float) $this->code(LedgerPosting::ACCOUNTS_PAYABLE)->balance());
    }

    /**
     * Two lines on the same account must combine into one journal line, not two
     * lines for the same account.
     */
    public function test_lines_sharing_an_account_are_combined(): void
    {
        $bill = $this->postBill([
            ['description' => 'Rent part 1', 'price' => 300.00, 'account' => $this->code('6100')],
            ['description' => 'Rent part 2', 'price' => 200.00, 'account' => $this->code('6100')],
        ]);

        $entry = $this->ledger()->journalFor($bill)->load('items');

        $rentLines = $entry->items->filter(
            fn ($i) => $i->account_id === $this->code('6100')->id,
        );

        $this->assertCount(1, $rentLines, 'one journal line per account, not per document line');
        $this->assertSame(500.00, (float) $rentLines->first()->debit);
    }

    /**
     * A bill raised without choosing an account still posts, to COGS as before.
     */
    public function test_a_line_without_an_account_falls_back_to_cogs(): void
    {
        $this->postBill([
            ['description' => 'Uncategorised goods', 'price' => 750.00],
        ]);

        $this->assertSame(750.00, (float) $this->code('5000')->balance());
    }

    public function test_a_bill_mixing_default_and_chosen_accounts_splits_correctly(): void
    {
        $this->postBill([
            ['description' => 'Uncategorised', 'price' => 100.00],
            ['description' => 'Bank fee', 'price' => 35.00, 'account' => $this->code('6300')],
        ]);

        $this->assertSame(100.00, (float) $this->code('5000')->balance());
        $this->assertSame(35.00, (float) $this->code('6300')->balance());
    }

    /**
     * The tax and the payable are independent of how the net is split.
     */
    public function test_tax_and_the_payable_are_unaffected_by_the_expense_split(): void
    {
        $bill = $this->postBill([
            ['description' => 'Services', 'price' => 1000.00, 'tax_percent' => 17, 'account' => $this->code('6000')],
        ]);

        $this->assertSame(1000.00, (float) $this->code('6000')->balance(), 'the net goes to the chosen account');
        $this->assertSame(
            170.00,
            (float) $this->code(LedgerPosting::INPUT_TAX_RECEIVABLE)->balance(),
            'tax is still recoverable input tax',
        );
        $this->assertSame(
            -1170.00,
            (float) $this->code(LedgerPosting::ACCOUNTS_PAYABLE)->balance(),
            'the supplier is still owed the gross',
        );
    }

    /**
     * Amending a bill re-posts from the lines, so the old split is voided and the
     * new accounts are used.
     */
    public function test_amending_a_bill_reposts_to_the_new_accounts(): void
    {
        $bill = $this->postBill([
            ['description' => 'Goods', 'price' => 400.00, 'account' => $this->code('5000')],
        ]);

$original = $this->ledger()->journalFor($bill);

        $this->assertSame(400.00, (float) $this->code('5000')->balance());

        $this->actingAs($this->admin())
            ->put("/suppliers/purchase-invoices/{$bill->id}", [
                'supplier_id' => $bill->supplier_id,
                'issue_date' => now()->toDateString(),
                'status' => 'draft',
                'currency' => 'USD',
                'items' => [[
                    'description' => 'Reclassified as rent',
                    'qty' => 1,
                    'unit_price' => '400.00',
                    'discount_percent' => 0,
                    'tax_percent' => 0,
                    'account_id' => $this->code('6100')->id,
                ]],
            ])
            ->assertSessionHas('toasts');

        $this->assertSame('void', $original->fresh()->status);
        $this->assertSame(0.00, (float) $this->code('5000')->balance(), 'the old classification is void');
        $this->assertSame(400.00, (float) $this->code('6100')->balance(), 'the new classification is posted');
    }

    /**
     * A line cannot be charged to revenue or a liability, or the P&L would be
     * credited for an expense.
     */
    public function test_a_line_cannot_be_charged_to_a_non_expense_account(): void
    {
        $supplier = $this->supplier();

        $this->actingAs($this->admin())
            ->post('/suppliers/purchase-invoices', [
                'supplier_id' => $supplier->id,
                'issue_date' => now()->toDateString(),
                'status' => 'draft',
                'currency' => 'USD',
                'items' => [[
                    'description' => 'Bad classification',
                    'qty' => 1,
                    'unit_price' => '100.00',
                    'discount_percent' => 0,
                    'tax_percent' => 0,
                    'account_id' => $this->code('4000')->id, // Sales Revenue
                ]],
            ])
            ->assertSessionHasErrors('items.0.account_id');

        $this->assertSame(
            0,
            JournalEntry::where('description', 'like', 'Purchase bill%')->count(),
            'a rejected classification must not post',
        );
    }
}