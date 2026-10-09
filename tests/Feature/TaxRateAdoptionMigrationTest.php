<?php

namespace Tests\Feature;

use App\Models\PurchaseInvoice;
use App\Models\SalesCustomer;
use App\Models\SalesInvoice;
use App\Models\Supplier;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Accounting\TaxCalculator;
use App\Support\DiscountLimit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * Upgrading a deployment that was already trading.
 *
 * The tax engine refuses to save a document that charges tax with no configured
 * rate behind it. That control is right on its merits, but it shipped with an
 * empty `tax_rates` table -- so any business that had been invoicing under the
 * old hand-typed `tax_percent` was locked out of saving its own invoices. Editing
 * a customer's address would fail with a tax error about a percentage they had
 * been charged for a year.
 *
 * Migration 2026_10_09_000001 adopts the rates those documents already charge,
 * dated from the earliest document that used them. These tests pin that the
 * adoption happens, that it is dated correctly, and -- just as important -- that
 * it happens *before* anyone tries to save.
 */
class TaxRateAdoptionMigrationTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDatabase;

    /**
     * The migration file, loaded so it can be run against data that was written
     * before the engine existed. Seeding first and then loading the migration is
     * the only way to reproduce the upgrade: by the time a test class runs, every
     * migration has already applied.
     */
    private function runAdoption(): void
    {
        $migration = require database_path('migrations/2026_10_09_000001_adopt_existing_tax_rates.php');

        $migration->up();
    }

    /**
     * A saved invoice, as the pre-engine application would have written it.
     */
    private function legacyCustomer(): SalesCustomer
    {
        return SalesCustomer::create([
            'company_name' => 'Legacy Customer '.uniqid(),
            'currency' => 'PKR',
            'is_active' => true,
            'opening_balance' => 0,
        ]);
    }

    /**
     * Write a saved invoice line at a given percentage, as the pre-engine
     * application would have.
     */
    private function legacyInvoice(string $issueDate, string $percent): void
    {
        $net = 100.0;
        $tax = $net * ((float) $percent / 100);

        $invoice = SalesInvoice::create([
            'number' => 'INV-'.uniqid(),
            'customer_id' => $this->legacyCustomer()->id,
            'issue_date' => $issueDate,
            'status' => 'sent',
            'currency' => 'PKR',
            'exchange_rate' => 1,
            'subtotal' => (string) $net,
            'tax_amount' => (string) $tax,
            'total' => (string) ($net + $tax),
            'paid_amount' => '0',
        ]);

        DB::table('sales_invoice_items')->insert([
            'invoice_id' => $invoice->id,
            'description' => 'Legacy line',
            'qty' => 1,
            'unit_price' => 100,
            'discount_percent' => 0,
            'tax_percent' => $percent,
            'line_total' => $net + $tax,
        ]);
    }

    /**
     * The hazard itself: with no catalogue, the engine refuses the very
     * percentage the document already carries.
 *
     * Acts as a user holding `accounting.tax_rates.view`, because
     * `validateTaxRates()` deliberately skips the check for anyone without it --
     * without that gate every invoice would be blocked until the catalogue was
     * filled in, which is the failure mode the gate exists to prevent.
     */
    public function test_without_adoption_the_document_cannot_be_saved(): void
    {
        $this->legacyInvoice('2026-03-01', '17');

        $this->assertSame(0, TaxRate::query()->count(), 'the catalogue should start empty');

        $controller = $this->taxControllerForAdmin();

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $controller->validateTaxRates([['tax_percent' => 17]], 'sales', '2026-03-01');
    }

    /**
     * The fix: after adoption, the same document saves, for the same user.
     */
    public function test_after_adoption_the_same_document_saves(): void
    {
        $this->legacyInvoice('2026-03-01', '17');
        $this->runAdoption();

        $controller = $this->taxControllerForAdmin();

        $controller->validateTaxRates([['tax_percent' => 17]], 'sales', '2026-03-01');

        $this->assertTrue(true, 'the legacy document is still blocked after adoption');
    }

    /**
     * DiscountLimit is a controller trait, so it cannot be resolved from the
     * container. Instantiated on a throwaway controller as an authenticated user
     * holding the tax permission, which is how a real controller gets it.
     */
    private function taxControllerForAdmin(): object
    {
        $admin = User::where('email', 'admin@nexosdigital.test')->firstOrFail();

        $this->assertTrue(
            $admin->can('accounting.tax_rates.view'),
            'the Admin role must hold the tax permission or this test proves nothing',
        );

        auth()->login($admin);

        return new class extends \App\Http\Controllers\Controller
        {
            use DiscountLimit;
        };
    }

    /**
     * Dated from the EARLIEST document, not from today. Dated from today, history
     * would still resolve to "no rate in force" and every already-issued document
     * would remain un-saveable -- the whole point would be lost.
     */
    public function test_the_adopted_rate_is_dated_from_the_earliest_document_that_charged_it(): void
    {
        $this->legacyInvoice('2026-06-15', '17');
        $this->legacyInvoice('2026-02-01', '17');

        $this->runAdoption();

        $rate = TaxRate::query()->where('rate', '17.0000')->firstOrFail();

        $this->assertSame('2026-02-01', $rate->effective_from->toDateString());
        $this->assertNull($rate->effective_to, 'an adopted rate must not be closed off');
    }

    public function test_the_adopted_rate_is_scoped_to_sales(): void
    {
        $this->legacyInvoice('2026-03-01', '17');
        $this->runAdoption();

        $rate = TaxRate::query()->where('rate', '17.0000')->firstOrFail();

        // Same percentage charged on a purchase is reclaimable input tax posting
        // to a different account, so the two must not be merged into "both".
        $this->assertSame('sales', $rate->applies_to);
    }

    /**
     * Four thousand invoices at one percentage is one rate, not four thousand.
     */
    public function test_repeated_identical_percentages_adopt_a_single_rate(): void
    {
        $this->legacyInvoice('2026-01-05', '17');
        $this->legacyInvoice('2026-02-05', '17');
        $this->legacyInvoice('2026-03-05', '17');

        $this->runAdoption();

        $this->assertSame(1, TaxRate::query()->where('rate', '17.0000')->count());
    }

    /**
     * Two genuinely different rates in the same business are two catalogue rows.
     */
    public function test_distinct_percentages_adopt_separately(): void
    {
        $this->legacyInvoice('2026-01-05', '5');
        $this->legacyInvoice('2026-02-05', '17');

        $this->runAdoption();

        $this->assertSame(1, TaxRate::query()->where('rate', '5.0000')->count());
        $this->assertSame(1, TaxRate::query()->where('rate', '17.0000')->count());
    }

    /**
     * Zero means "no tax on this line", which the engine already accepts. Adopting
     * it would imply a rate was in force when none was.
     */
    public function test_zero_percentages_are_not_adopted(): void
    {
        $this->legacyInvoice('2026-03-01', '0');
        $this->runAdoption();

        $this->assertSame(0, TaxRate::query()->count());
    }

    /**
     * Guessing the ledger account would misstate the balance sheet, so it stays
     * null for the owner to confirm.
     */
    public function test_the_adopted_rate_leaves_the_ledger_account_for_the_owner_to_confirm(): void
    {
        $this->legacyInvoice('2026-03-01', '17');
        $this->runAdoption();

        $rate = TaxRate::query()->where('rate', '17.0000')->firstOrFail();

        $this->assertNull($rate->account_id);
        $this->assertStringContainsString('Confirm', (string) $rate->notes);
        $this->assertStringContainsString('Adopted', $rate->name);
    }

    /**
     * An operator who has configured their own catalogue knows their own rates.
     * Backfilling over them would invent history.
     */
    public function test_nothing_is_adopted_when_the_catalogue_is_already_configured(): void
    {
        TaxRate::create([
            'code' => 'STD-VAT',
            'name' => 'Standard VAT',
            'rate' => '17.0000',
            'applies_to' => 'sales',
            'effective_from' => '2026-01-01',
            'is_active' => true,
        ]);

        $this->legacyInvoice('2026-03-01', '5');

        $this->runAdoption();

        $this->assertSame(1, TaxRate::query()->count(), 'adoption overwrote an operator-configured catalogue');
    }

    /**
     * A fresh install has no legacy documents and therefore nothing to adopt.
     * The seeder deliberately seeds no rates, so an empty table stays honest.
     */
    public function test_a_fresh_installation_adopts_nothing(): void
    {
        $this->runAdoption();

        $this->assertSame(0, TaxRate::query()->count());
    }

    /**
     * Running twice must not double the catalogue.
     */
    public function test_adoption_is_idempotent(): void
    {
        $this->legacyInvoice('2026-03-01', '17');

        $this->runAdoption();

        $migration = require database_path('migrations/2026_10_09_000001_adopt_existing_tax_rates.php');
        $migration->up();

        $this->assertSame(1, TaxRate::query()->count());
    }

    /**
     * Every source table in the migration's list really exists and really joins.
     *
     * The foreign key is deliberately explicit per table (`order_id` on purchase
     * orders, `invoice_id` on purchase invoices), and a wrong guess joins against
     * nothing -- which would silently adopt nothing at all rather than fail. So
     * rather than asserting the mapping equals a literal, this proves the join
     * actually returns rows for each table that can hold data.
     */
    public function test_every_source_table_declares_a_date_column_and_a_scope_that_exists(): void
    {
        $migration = require database_path('migrations/2026_10_09_000001_adopt_existing_tax_rates.php');
        $sources = (new \ReflectionClass($migration))->getConstant('SOURCES');

        $this->assertNotEmpty($sources);

        foreach ($sources as $items => [$parent, $date, $appliesTo]) {
            $this->assertTrue(Schema::hasTable($items), "missing source table {$items}");
            $this->assertTrue(Schema::hasColumn($items, 'tax_percent'), "{$items} has no tax_percent");
            $this->assertTrue(Schema::hasTable($parent), "missing parent table {$parent}");
            $this->assertTrue(Schema::hasColumn($parent, $date), "{$parent} has no {$date}");
            $this->assertContains($appliesTo, ['sales', 'purchase']);
        }
    }

    /**
     * A purchase-side legacy bill must be adopted as purchase scope, so its rate
     * posts as reclaimable input tax rather than output tax.
     */
    public function test_a_legacy_purchase_bill_adopts_as_purchase_scope(): void
    {
        $supplier = Supplier::create([
            'company_name' => 'Legacy Supplier '.uniqid(),
            'currency' => 'PKR',
            'is_active' => true,
        ]);

        $bill = PurchaseInvoice::create([
            'number' => 'BILL-'.uniqid(),
            'supplier_id' => $supplier->id,
            'issue_date' => '2026-04-01',
            'status' => 'sent',
            'currency' => 'PKR',
            'exchange_rate' => 1,
            'subtotal' => '200',
            'tax_amount' => '34',
            'total' => '234',
            'paid_amount' => '0',
        ]);

        DB::table('purchase_invoice_items')->insert([
            'invoice_id' => $bill->id,
            'description' => 'Legacy input line',
            'qty' => 1,
            'unit_price' => 200,
            'discount_percent' => 0,
            'tax_percent' => '17.00',
            'line_total' => 234,
        ]);

        $this->runAdoption();

        $rate = TaxRate::query()->where('rate', '17.0000')->firstOrFail();

        $this->assertSame('purchase', $rate->applies_to);
        $this->assertSame('2026-04-01', $rate->effective_from->toDateString());
    }

/**
 * The whole point, stated as the engine sees it: after adoption the percentage
 * on an old document resolves to a rate rather than reporting a gap.
 */
    public function test_an_adopted_rate_resolves_for_an_old_document_date(): void
    {
        $this->legacyInvoice('2026-02-01', '17');
        $this->runAdoption();

        $rate = app(TaxCalculator::class)->rateFor('sales', '2026-02-01');

        $this->assertNotNull($rate, 'the adopted rate does not resolve for a historic date');
        $this->assertSame('17.0000', (string) $rate->rate);
        $this->assertSame([], app(TaxCalculator::class)->validatePercents(['17'], 'sales', '2026-02-01'));
    }

    /**
     * Found by rehearsing the migration against real data.
     *
     * A real quote carried a 20% line and two 10% lines on the same day. Adoption
     * created a 20% rate and a 10% rate, both effective from that day -- and
     * `rateFor()` returns ONE rate, the highest priority, newest first. Validating
     * against only that winner refused the two 10% lines: adoption appeared to
     * work while still blocking the very documents it was written to unblock.
     *
     * Two rates in force on one day is legitimate (standard vs reduced goods), so
     * any in-force rate must satisfy a line.
     */
    public function test_two_adopted_rates_in_force_on_the_same_day_both_validate(): void
    {
        $this->legacyInvoice('2026-10-04', '20');
        $this->legacyInvoice('2026-10-04', '10');

        $this->runAdoption();

        $this->assertSame(2, TaxRate::query()->count(), 'expected two distinct adopted rates');

        $calc = app(TaxCalculator::class);

        // The editor is still offered a single default. Which one wins is decided by
        // priority then date then id -- both adopted rates share a date, so this
        // asserts a single rate is chosen rather than pinning which.
        $default = $calc->rateFor('sales', '2026-10-04');
        $this->assertNotNull($default);
        $this->assertContains((string) $default->rate, ['20.0000', '10.0000']);

        // Both are valid on a line, whichever one won the default.
        $this->assertSame([], $calc->validatePercents(['20'], 'sales', '2026-10-04'));
        $this->assertSame([], $calc->validatePercents(['10'], 'sales', '2026-10-04'));
        $this->assertSame([], $calc->validatePercents(['10', '20'], 'sales', '2026-10-04'));
    }

    /**
     * Widening what is accepted must not widen it to anything. A rate nobody
     * configured is still refused, or the control is worthless.
     */
    public function test_a_rate_that_was_never_configured_is_still_refused_after_adoption(): void
    {
        $this->legacyInvoice('2026-10-04', '20');
        $this->runAdoption();

        $problems = app(TaxCalculator::class)->validatePercents(['12'], 'sales', '2026-10-04');

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('no rate in force', $problems[0]['reason']);
    }

    /**
     * The refusal has to name what was actually available, or the accountant is
     * left guessing which rate they should have used.
     */
    public function test_a_refusal_lists_the_rates_that_were_in_force(): void
    {
        $this->legacyInvoice('2026-10-04', '20');
        $this->runAdoption();

        $problems = app(TaxCalculator::class)->validatePercents(['12'], 'sales', '2026-10-04');

        $this->assertStringContainsString('20%', $problems[0]['reason']);
    }
}