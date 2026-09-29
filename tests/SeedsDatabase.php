<?php

namespace Tests;

use App\Models\Account;
use App\Models\Employee;
use App\Models\InventoryItem;
use App\Models\InventoryWarehouse;
use App\Models\Product;
use App\Models\Role;
use App\Models\SalesCustomer;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\Subscription;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

trait SeedsDatabase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        // CheckSubscription blocks every non-Super-Admin when there is no active
        // package, so tests exercising staff routes need one.
        Subscription::create([
            'plan_name' => 'Test Plan',
            'starts_at' => now()->subMonth(),
            'expires_at' => now()->addYear(),
            'is_active' => true,
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function setUpDatabase(): void
    {
        $this->seed(DatabaseSeeder::class);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function userForRole(string $roleName, array $attributes = []): User
    {
        $role = Role::where('name', $roleName)->firstOrFail();

        $user = User::create(array_merge([
            'name' => $roleName.' User',
            'email' => str($roleName)->slug().'@example.test',
            'password' => 'Password123!',
            'is_active' => true,
            'email_verified_at' => now(),
        ], $attributes));

        $user->assignRole($role);

        return $user->fresh();
    }

    /**
     * A user with a linked, attendance-tracked employee profile.
     */
    protected function employeeUserForRole(string $roleName, array $userAttributes = [], array $employeeAttributes = []): User
    {
        $user = $this->userForRole($roleName, $userAttributes);

        Employee::create(array_merge([
            'user_id' => $user->id,
            'employee_code' => 'EMP-'.strtoupper(str($roleName)->substr(0, 6)->value()),
            'first_name' => 'Test',
            'last_name' => $roleName,
            'email' => $user->email,
            'date_hired' => now()->subYear()->toDateString(),
            'employment_status' => 'active',
            'attendance_enabled' => true,
        ], $employeeAttributes));

        return $user->fresh();
    }

    protected function warehouse(string $name = 'Main'): InventoryWarehouse
    {
        return InventoryWarehouse::firstOrCreate(['name' => $name], [
            // inventory_warehouses has no is_default column — only name, code,
            // address, is_active and the soft-delete timestamps.
            'code' => strtoupper(substr(md5($name), 0, 6)),
        ]);
    }

    /**
     * Create (or fetch) a chart-of-accounts account.
     *
     * The chart of accounts is deliberately not seeded — it is company-specific
     * bookkeeping an accountant sets up per deployment, and no module generates
     * journal entries, so the statements stay empty until they do. Ledger tests
     * therefore build the accounts they need rather than relying on seeded rows.
     */
    protected function account(string $code, string $type = 'asset', string $name = 'Test account'): Account
    {
        return Account::firstOrCreate(
            ['code' => $code],
            [
                'name' => $name,
                'type' => $type,
                'currency' => 'USD',
                'is_active' => true,
            ],
        );
    }

    /**
     * A debit-normal account (cash) and a credit-normal account (revenue), the
     * minimum pair needed to post a balanced journal.
     *
     * @return array{0: Account, 1: Account}
     */
    protected function ledgerAccounts(): array
    {
        return [
            $this->account('1000', 'asset', 'Cash & cash equivalents'),
            $this->account('4000', 'revenue', 'Sales revenue'),
        ];
    }

    /**
     * A product plus its linked stock item.
     *
     * @return array{0: Product, 1: InventoryItem}
     */
    protected function productWithStockItem(
        string $name = 'Widget',
        string $reorderLevel = '0',
        string $reorderQty = '0',
    ): array {
        $product = Product::create([
            'name' => $name,
            'sku' => 'SKU-'.strtoupper(substr(md5($name.microtime(true)), 0, 8)),
            'is_active' => true,
        ]);

        $item = InventoryItem::create([
            'product_id' => $product->id,
            'reorder_level' => $reorderLevel,
            'reorder_quantity' => $reorderQty,
            'is_active' => true,
        ]);

        return [$product, $item];
    }

    protected function customer(string $name = 'Acme Co', ?string $email = null): SalesCustomer
    {
        return SalesCustomer::create([
            'company_name' => $name,
            'email' => $email ?? str($name)->slug().'@example.test',
            'is_active' => true,
        ]);
    }

    protected function supplier(string $name = 'Vendor Ltd'): Supplier
    {
        return Supplier::create([
            'company_name' => $name,
            'is_active' => true,
        ]);
    }

    /**
     * A confirmed-ready sales order with one line item.
     */
    protected function salesOrder(SalesCustomer $customer, Product $product, string $qty = '1', string $price = '100.00', string $status = 'draft'): SalesOrder
    {
        $order = SalesOrder::create([
            'number' => next_document_number('order', 'SO'),
            'customer_id' => $customer->id,
            'issue_date' => now()->toDateString(),
            'status' => $status,
            'currency' => base_currency(),
            'exchange_rate' => 1,
            'subtotal' => '0',
            'tax_amount' => '0',
            'total' => '0',
        ]);

        SalesOrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'description' => $product->name,
            'qty' => $qty,
            'unit_price' => $price,
            'line_total' => bcmul($qty, $price, 2),
        ]);

        $order->update([
            'subtotal' => (string) bcmul($qty, $price, 2),
            'total' => (string) bcmul($qty, $price, 2),
        ]);

        return $order->fresh();
    }
}
