<?php

namespace Tests\Feature\Stress;

use App\Models\SalesInvoice;
use App\Models\SalesPayment;
use App\Models\User;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * Volume behaviour: the same screens and the same arithmetic at a data volume
 * a real deployment reaches.
 *
 * Every test asserts a *correctness* property under volume (the totals still
 * add up) rather than a timing threshold, because timings are not reproducible
 * on shared hosting and a flaky suite is worse than a slow one.
 */
class BulkDataStressTest extends TestCase
{
    use SeedsDatabase;

    /**
     * A customer's balance must stay exact after many invoices, and again
     * after half of them are settled.
     */
    public function test_customer_balance_is_exact_with_many_invoices(): void
    {
        $customer = $this->customer('Bulk Co');
        $expectedOutstanding = 0.0;

        foreach (range(1, 60) as $index) {
            $total = 100.0 + $index;

            SalesInvoice::create([
                'number' => next_document_number('invoice', 'INV'),
                'customer_id' => $customer->id,
                'issue_date' => now()->subDays($index)->toDateString(),
                'status' => 'sent',
                'currency' => 'USD',
                'exchange_rate' => 1,
                'subtotal' => $total,
                'tax_amount' => 0,
                'total' => $total,
            ]);

            $expectedOutstanding += $total;
        }

        $this->assertSame(
            round($expectedOutstanding, 2),
            round((float) $customer->fresh()->balance(), 2),
            'Sum of 60 open invoices must equal the reported customer balance.',
        );

        $settled = 0.0;

        // Settle the way the application settles: a customer balance is
        // derived from the payments relation (SalesCustomer::balance() sums
        // payments, not paid_amount), so writing paid_amount directly would
        // prove nothing about the figure the business actually sees.
        foreach (SalesInvoice::query()->where('customer_id', $customer->id)->limit(30)->get() as $invoice) {
            SalesPayment::create([
                'number' => 'BULK-'.uniqid(),
                'invoice_id' => $invoice->id,
                'customer_id' => $customer->id,
                'amount' => (string) $invoice->total,
                'payment_date' => now()->toDateString(),
                'method' => 'bank_transfer',
                'currency' => 'USD',
                'exchange_rate' => 1,
            ]);

            $settled += (float) $invoice->total;
        }

        $this->assertSame(
            round($expectedOutstanding - $settled, 2),
            round((float) $customer->fresh()->balance(), 2),
            'Settling half of 60 invoices must halve the balance exactly.',
        );
    }

    /**
     * Document numbering under repetition: N calls must yield N distinct
     * consecutive numbers with no gaps and no duplicates.
     */
    public function test_document_numbers_stay_sequential_under_repetition(): void
    {
        $numbers = [];

        foreach (range(1, 40) as $ignored) {
            $numbers[] = next_document_number('invoice', 'STRESS');
        }

        $this->assertSame(
            count($numbers),
            count(array_unique($numbers)),
            'Document numbers must be unique across repeated calls.',
        );

        $suffixes = array_map(
            fn (string $number) => (int) substr($number, strrpos($number, '-') + 1),
            $numbers,
        );

        sort($suffixes);

        $this->assertSame(
            range($suffixes[0], $suffixes[0] + 39),
            $suffixes,
            'Document numbers must be consecutive — no skipped values.',
        );
    }

    /**
     * Pagination must not silently truncate or repeat: the pages a user walks
     * through must show disjoint rows.
     */
    public function test_invoice_pagination_never_repeats_a_row(): void
    {
        $customer = $this->customer('Paging Co');

        foreach (range(1, 47) as $index) {
            SalesInvoice::create([
                'number' => 'PG-'.str_pad((string) $index, 4, '0', STR_PAD_LEFT),
                'customer_id' => $customer->id,
                'issue_date' => now()->toDateString(),
                'status' => 'sent',
                'currency' => 'USD',
                'exchange_rate' => 1,
                'subtotal' => 10,
                'tax_amount' => 0,
                'total' => 10,
            ]);
        }

        $admin = $this->userForRole('Admin');
        $seen = [];

        foreach ([1, 2, 4] as $page) {
            $response = $this->actingAs($admin)->get('/sales/invoices?page='.$page);

            $response->assertOk();

            preg_match_all('/PG-(\d{4})/', $response->getContent(), $matches);

            // An invoice number can legitimately appear more than once in one
            // page (row link, sort key, hidden export field), so uniqueness is
            // measured per page; a repeat ACROSS pages is the actual defect.
            foreach (array_unique($matches[1]) as $number) {
                $seen[] = $number;
            }
        }

        $this->assertNotEmpty($seen);
        $this->assertSame(
            count($seen),
            count(array_unique($seen)),
            'A paginated invoice list must not repeat a row across pages: '
            .count($seen).' rows walked, '.count(array_unique($seen)).' distinct.',
        );
    }

    /**
     * The dashboard and the user-administration screen must stay correct with
     * a realistic ledger and staff list behind them.
     */
    public function test_dashboard_and_user_admin_render_with_a_full_dataset(): void
    {
        $customer = $this->customer('Dashboard Volume Co');

        foreach (range(1, 50) as $index) {
            SalesInvoice::create([
                'number' => next_document_number('invoice', 'INV'),
                'customer_id' => $customer->id,
                'issue_date' => now()->subDays($index % 30)->toDateString(),
                'status' => $index % 3 === 0 ? 'paid' : 'sent',
                'currency' => 'USD',
                'exchange_rate' => 1,
                'subtotal' => 500,
                'tax_amount' => 0,
                'total' => 500,
                'paid_amount' => $index % 3 === 0 ? 500 : 0,
            ]);
        }

        foreach (range(1, 30) as $index) {
            User::create([
                'name' => 'Bulk User '.$index,
                'first_name' => 'Bulk',
                'last_name' => 'User'.$index,
                'email' => "bulk{$index}@example.test",
                'password' => 'Password123!',
                'is_active' => true,
                'email_verified_at' => now(),
            ]);
        }

        // User administration belongs to the Admin role. The seeded Super Admin is
        // deliberately excluded from settings.users.* by RolesSeeder (it holds
        // the system-settings permissions only), so it is the wrong persona
        // for this screen and is exercised separately.
        $admin = $this->userForRole('Admin');

        $this->actingAs($admin)->get('/dashboard')->assertOk();
        $this->actingAs($admin)->get('/settings/users')->assertOk();
        $this->actingAs($admin)->get('/settings/users?search=Bulk&status=active')->assertOk();
    }

    /**
     * The separation of duties is a documented decision, not an accident: the
     * role named "Super Admin" cannot administer users, and the role named
     * "Admin" can. Anyone who signs in as the account they were given after
     * install lands on the 403 for the screen they expect first.
     */
    public function test_the_super_admin_role_cannot_administer_users(): void
    {
        $superAdmin = User::where('email', 'superadmin@nexosdigital.test')->firstOrFail();

        $this->assertFalse(
            $superAdmin->can('settings.users.view'),
            'RolesSeeder is expected to keep settings.users.* out of the Super Admin role.',
        );

        $this->actingAs($superAdmin)->get('/settings/users')->assertForbidden();
    }
}