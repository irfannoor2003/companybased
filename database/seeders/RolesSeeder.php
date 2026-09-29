<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Support\Permissions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

class RolesSeeder extends Seeder
{
    /**
     * New 6-role structure replacing the old 8-role set.
     * Removed: Auditor, Accountant, Procurement.
     * Added:   Employee (generic staff attendance).
     */
    protected function roleDefinitions(): array
    {
        return [
            [
                'name' => config('roles.super_admin'),
                'label' => 'Super Admin',
                'description' => 'Company configuration only — profile, branding, modules, base currency, backups and audit. No business data access.',
                'is_system' => true,
                'permissions' => 'super_admin',
            ],
            [
                'name' => config('roles.admin'),
                'label' => 'Admin',
                'description' => 'Full CRUD + export across all enabled modules, plus staff and role management.',
                'is_system' => true,
                'permissions' => 'admin',
            ],
            [
                'name' => config('roles.hr'),
                'label' => 'HR',
                'description' => 'Read-only and export across all modules. Can create staff accounts for attendance purposes.',
                'is_system' => true,
                'permissions' => 'hr',
            ],
            [
                'name' => config('roles.accountant'),
                'label' => 'Accountant',
                'description' => 'Manages accounting, banking, supplier bills, cash flow and financial reporting.',
                'is_system' => true,
                'permissions' => 'accountant',
            ],
            [
                'name' => config('roles.salesman'),
                'label' => 'Salesman',
                'description' => 'Manages customers, creates quotes/orders, GPS-tracked visits. Limited inventory visibility.',
                'is_system' => true,
                'permissions' => 'salesman',
            ],
            [
                'name' => config('roles.inventory_manager'),
                'label' => 'Inventory Manager',
                'description' => 'Fulfills confirmed orders, creates invoices, manages product catalog, stock and warehouses.',
                'is_system' => true,
                'permissions' => 'inventory_manager',
            ],
            [
                'name' => config('roles.employee'),
                'label' => 'Employee',
                'description' => 'Self-service attendance and leave. Default role for general staff who need to clock in and request leave.',
                'is_system' => true,
                'permissions' => 'employee',
            ],
        ];
    }

    public function run(): void
    {
        $roles = $this->roleDefinitions();

        $all = Permissions::all();
        $readOnly = Permissions::readOnly();

        // ── 1. Super Admin ──────────────────────────────────────────────
        // Company configuration only. No business module data, no user/role management.
        // Deliberately no employees.* self-service permissions: Super Admin is a
        // back-office account, not a member of staff, so it is never on the payroll.
        $superAdmin = [
            'dashboard.overview.view',
            // Company profile
            'settings.company.view',
            'settings.company.manage',
            // Branding
            'settings.branding.view',
            'settings.branding.manage',
            // Modules
            'settings.modules.view',
            'settings.modules.manage',
            'settings.dashboard.view',
            'settings.dashboard.manage',
            // Base currency
            'settings.base_currency.view',
            'settings.base_currency.manage',
            // Backup & restore
            'settings.backup.view',
            'settings.backup.manage',
            // Audit log
            'settings.audit.view',
            'settings.audit.export',
            // Mail server
            'settings.mail.view',
            'settings.mail.manage',
            // Packages / subscription
            'settings.subscription.view',
            'settings.subscription.manage',
        ];

        // ── 2. Admin ───────────────────────────────────────────────────
        // Everything except Super-Admin-only settings.
        // Also stripped of employees.my_attendance.* for the same reason as
        // Super Admin: an Admin login has no employee profile and is not
        // attendance-tracked. The `employee` middleware enforces this at runtime.
        $superAdminSettings = [
            'settings.company.view', 'settings.company.manage',
            'settings.modules.view', 'settings.modules.manage',
            'settings.base_currency.view', 'settings.base_currency.manage',
            'settings.backup.view', 'settings.backup.manage',
            'settings.audit.view', 'settings.audit.export',
            'settings.mail.view', 'settings.mail.manage',
            'settings.subscription.view', 'settings.subscription.manage',
            'employees.my_attendance.view', 'employees.my_attendance.mark',
            'employees.my_leave.view',
        ];
        $admin = array_values(array_unique(array_merge(
            array_diff($all, $superAdminSettings),
            ['settings.dashboard.view', 'settings.dashboard.manage'],
        )));

        // ── 3. HR ──────────────────────────────────────────────────────
        // Read-only + export across all modules, plus staff creation.
        $hr = array_values(array_diff(array_unique(array_merge(
            $readOnly,
            ['settings.users.create'],
            ['employees.holidays.manage'],
            ['employees.my_attendance.view'],
            ['employees.my_attendance.mark'],
            ['employees.leave_requests.approve'],
        )), ['reports.grand.view', 'reports.grand.export']));

        // ── 4. Accountant ──────────────────────────────────────────────
        $accountant = [
            'dashboard.overview.view',
            'reports.reports.view',
            'reports.reports.export',
            'reports.grand.view',
            'reports.grand.export',
            'employees.my_attendance.view',
            'employees.my_attendance.mark',
            'accounting.chart_of_accounts.view',
            'accounting.chart_of_accounts.create',
            'accounting.chart_of_accounts.edit',
            'accounting.chart_of_accounts.delete',
            'accounting.chart_of_accounts.export',
            'accounting.journal_entries.view',
            'accounting.journal_entries.create',
            'accounting.journal_entries.edit',
            'accounting.journal_entries.delete',
            'accounting.journal_entries.export',
            'accounting.expense_claims.view',
            'accounting.expense_claims.create',
            'accounting.expense_claims.edit',
            'accounting.expense_claims.delete',
            'accounting.expense_claims.export',
            'accounting.bills.view',
            'accounting.bills.create',
            'accounting.bills.edit',
            'accounting.bills.delete',
            'accounting.bills.export',
            'accounting.bills.record_payment',
            'accounting.tax_returns.view',
            'accounting.tax_returns.create',
            'accounting.tax_returns.edit',
            'accounting.tax_returns.delete',
            'accounting.tax_returns.export',
            'accounting.budgeting.view',
            'accounting.budgeting.create',
            'accounting.budgeting.edit',
            'accounting.budgeting.delete',
            'accounting.budgeting.export',
            'banking.accounts.view',
            'banking.accounts.create',
            'banking.accounts.edit',
            'banking.accounts.delete',
            'banking.accounts.export',
            'banking.transactions.view',
            'banking.transactions.create',
            'banking.transactions.edit',
            'banking.transactions.delete',
            'banking.transactions.export',
            'banking.transfers.view',
            'banking.transfers.create',
            'banking.transfers.edit',
            'banking.transfers.delete',
            'banking.transfers.export',
            'banking.reconciliations.view',
            'banking.reconciliations.create',
            'banking.reconciliations.edit',
            'banking.reconciliations.delete',
            'banking.reconciliations.export',
            'cash_flow.overview.view',
            'cash_flow.overview.export',
            'cash_flow.inflows.view',
            'cash_flow.inflows.export',
            'cash_flow.outflows.view',
            'cash_flow.outflows.export',
            'cash_flow.forecast.view',
            'cash_flow.forecast.export',
            'cash_flow.reports.view',
            'cash_flow.reports.export',
            'suppliers.suppliers.view',
            'suppliers.purchase_invoices.view',
            'suppliers.purchase_invoices.create',
            'suppliers.purchase_invoices.edit',
            'suppliers.purchase_invoices.export',
            'suppliers.purchase_invoices.record_payment',
            'suppliers.supplier_payments.view',
            'suppliers.supplier_payments.create',
            'suppliers.supplier_payments.edit',
            'suppliers.supplier_payments.delete',
            'suppliers.supplier_payments.export',
            'suppliers.supplier_ledger.view',
            'suppliers.supplier_ledger.export',
            'sales.invoices.view',
            'sales.sales_payments.view',
            'sales.sales_payments.create',
            'sales.sales_payments.edit',
            'sales.sales_payments.delete',
            'sales.sales_payments.export',
        ];

        // ── 5. Salesman ────────────────────────────────────────────────
        $salesman = [
            'dashboard.overview.view',
            'reports.reports.view',
            // Catalog (view only)
            'catalog.products.view',
            'catalog.price_lists.view',
            // Sales — customers (full CRUD + email)
            'sales.customers.view',
            'sales.customers.create',
            'sales.customers.edit',
            'sales.customers.delete',
            'sales.customers.email',
            // Sales — quotes
            'sales.quotes.view',
            'sales.quotes.create',
            'sales.quotes.edit',
            'sales.quotes.delete',
            'sales.quotes.convert',
            // Sales — orders
            'sales.orders.view',
            'sales.orders.create',
            'sales.orders.edit',
            // Sales — invoices (view only)
            'sales.invoices.view',
            // Sales — payments received (view, e.g. payment account + status)
            'sales.sales_payments.view',
            // Sales — tracking & statements
            'sales.tracking.view',
            'sales.statements.view',
            // Sales — reports
            'sales.reports.view',
            // Inventory (view only)
            'inventory.items.view',
            // Visits
            'visits.visits.view',
            'visits.visits.create',
            'visits.visits.edit',
            'visits.visits.export',
            'visits.pit_stops.view',
            'visits.pit_stops.create',
            'visits.pit_stops.edit',
            // Attendance
            'employees.my_attendance.view',
            'employees.my_attendance.mark',
            // Leave (my own requests)
            'employees.my_leave.view',
            'employees.my_leave.create',
            'employees.my_leave.cancel',
        ];

        // ── 5. Inventory Manager ───────────────────────────────────────
        $inventoryManager = [
            'dashboard.overview.view',
            'reports.reports.view',
            // Sales — orders (view + status)
            'sales.orders.view',
            'sales.orders.update_status',
            // Sales — invoices (create from confirmed orders, edit incl. PDF template)
            'sales.invoices.view',
            'sales.invoices.create',
            'sales.invoices.edit',
            // Sales — payments received (view, e.g. payment account + status)
            'sales.sales_payments.view',
            // Sales — delivery notes
            'sales.delivery_notes.view',
            'sales.delivery_notes.create',
            'sales.delivery_notes.edit',
            'sales.delivery_notes.update_status',
            // Sales — tracking
            'sales.tracking.view',
            'sales.tracking.update_status',
            // Sales — reports
            'sales.reports.view',
            // Catalog (full CRUD)
            'catalog.products.view',
            'catalog.products.create',
            'catalog.products.edit',
            'catalog.products.delete',
            'catalog.categories.view',
            'catalog.categories.create',
            'catalog.categories.edit',
            'catalog.categories.delete',
            'catalog.brands.view',
            'catalog.brands.create',
            'catalog.brands.edit',
            'catalog.brands.delete',
            // Inventory (full CRUD + adjust stock)
            'inventory.items.view',
            'inventory.items.create',
            'inventory.items.edit',
            'inventory.items.delete',
            'inventory.items.adjust_stock',
            'inventory.incoming_shipments.view',
            'inventory.incoming_shipments.create',
            'inventory.incoming_shipments.receive',
            'inventory.incoming_shipments.approve',
            'inventory.transfers.view',
            'inventory.transfers.create',
            'inventory.transfers.edit',
            'inventory.write_offs.view',
            'inventory.write_offs.create',
            'inventory.warehouses.view',
            'inventory.warehouses.create',
            'inventory.warehouses.edit',
            // Attendance
            'employees.my_attendance.view',
            'employees.my_attendance.mark',
        ];

        // ── 6. Employee ────────────────────────────────────────────────
        // Self-service attendance only + dashboard landing.
        $employee = [
            'dashboard.overview.view',
            'employees.my_attendance.view',
            'employees.my_attendance.mark',
            'employees.my_leave.view',
            'employees.my_leave.create',
            'employees.my_leave.cancel',
        ];

        // ── Cleanup: remove old roles no longer in the new set ─────────
        $newRoleNames = array_column($roles, 'name');
        $oldRoles = Role::whereNotIn('name', $newRoleNames)->get();
        foreach ($oldRoles as $oldRole) {
            // Unassign all users from this role before deleting
            $oldRole->users()->detach();
            $oldRole->delete();
        }

        // ── Seed each role ─────────────────────────────────────────────
        foreach ($roles as $definition) {
            $role = Role::findOrCreate($definition['name'], 'web');

            $role->forceFill([
                'label' => $definition['label'],
                'description' => $definition['description'],
                'is_system' => $definition['is_system'],
            ])->save();

            $permissions = match ($definition['permissions']) {
                'super_admin' => $superAdmin,
                'admin' => $admin,
                'hr' => $hr,
                'accountant' => $accountant,
                'salesman' => $salesman,
                'inventory_manager' => $inventoryManager,
                'employee' => $employee,
                default => $definition['permissions'],
            };

            $permissionModels = Permission::whereIn('name', $permissions)->pluck('id')->all();
            $this->syncRolePermissions($role, $permissionModels);
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    /**
     * Attach a role's permissions in one insert rather than Spatie's per-row
     * sync.
     *
     * Spatie's syncPermissions() issues a DELETE plus one INSERT per permission,
     * and the Admin role alone holds 370 of them. Because the seeder runs once
     * per test, that was the single largest cost in the suite.
     */
    private function syncRolePermissions(Role $role, array $permissionIds): void
    {
        $table = config('permission.table_names.role_has_permissions');

        DB::table($table)->where('role_id', $role->id)->delete();

        if ($permissionIds === []) {
            return;
        }

        $rows = array_map(fn ($permissionId): array => [
            'permission_id' => $permissionId,
            'role_id' => $role->id,
        ], $permissionIds);

        // Chunked so a very large role cannot blow past the placeholder limit.
        foreach (array_chunk($rows, 400) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }
}
