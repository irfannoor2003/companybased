<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Support\Permissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

class RolesSeeder extends Seeder
{
    /**
     * Predefined roles and their granular permission assignments. Every entry
     * is data (editable from the Role settings UI) — none of this is hardcoded
     * into application logic.
     */
    protected array $roles = [
        [
            'name' => 'Super Admin',
            'label' => 'Super Admin',
            'description' => 'System setup only. Configures company profile, branding, modules, users, roles, currencies and backups. No access to operational data.',
            'is_system' => true,
            'permissions' => 'setup',
        ],
        [
            'name' => 'Admin',
            'label' => 'Admin',
            'description' => 'Full control over all enabled modules, users, roles, permissions and document templates.',
            'is_system' => true,
            'permissions' => 'admin',
        ],
        [
            'name' => 'HR',
            'label' => 'HR',
            'description' => 'View and export data across all modules. No create, edit or delete rights.',
            'is_system' => true,
            'permissions' => 'read_only',
        ],
        [
            'name' => 'Salesman',
            'label' => 'Salesman',
            'description' => 'Manages customers, creates quotes, converts to orders and runs GPS-tracked visits.',
            'is_system' => true,
            'permissions' => [
                'dashboard.overview.view',
                'reports.reports.view',
                'catalog.products.view',
                'catalog.price_lists.view',
                'sales.customers.view', 'sales.customers.create', 'sales.customers.edit',
                'sales.quotes.view', 'sales.quotes.create', 'sales.quotes.edit', 'sales.quotes.delete', 'sales.quotes.convert',
                'sales.orders.view', 'sales.orders.create', 'sales.orders.edit',
                'sales.invoices.view',
                'sales.tracking.view',
                'visits.visits.view', 'visits.visits.create', 'visits.visits.edit',
                'visits.pit_stops.view', 'visits.pit_stops.create',
                'employees.my_attendance.view', 'employees.my_attendance.mark',
            ],
        ],
        [
            'name' => 'Inventory Manager',
            'label' => 'Inventory Manager',
            'description' => 'Receives confirmed orders, manages pack/ship status, stock levels and warehouse transfers.',
            'is_system' => true,
            'permissions' => [
                'dashboard.overview.view',
                'reports.reports.view',
                'sales.orders.view', 'sales.orders.update_status',
                'sales.delivery_notes.view', 'sales.delivery_notes.create', 'sales.delivery_notes.edit', 'sales.delivery_notes.update_status',
                'sales.tracking.view', 'sales.tracking.update_status',
                'inventory.items.view', 'inventory.items.create', 'inventory.items.edit', 'inventory.items.adjust_stock', 'inventory.items.export',
                'inventory.transfers.view', 'inventory.transfers.create', 'inventory.transfers.edit',
                'inventory.write_offs.view', 'inventory.write_offs.create',
                'inventory.production_orders.view', 'inventory.production_orders.update_status',
                'inventory.bill_of_materials.view',
                'inventory.warehouses.view', 'inventory.warehouses.create', 'inventory.warehouses.edit',
                'employees.my_attendance.view', 'employees.my_attendance.mark',
            ],
        ],
        [
            'name' => 'Accountant',
            'label' => 'Accountant / Bookkeeper',
            'description' => 'Full access to Accounting, Banking, Cash Flow and Reports. Cannot manage users or roles.',
            'is_system' => true,
            'permissions' => 'accounting_banking',
        ],
        [
            'name' => 'Procurement',
            'label' => 'Procurement / Purchasing',
            'description' => 'Manages suppliers and the full purchase cycle from quotes to paid invoices.',
            'is_system' => true,
            'permissions' => [
                'dashboard.overview.view',
                'catalog.products.view',
                'inventory.items.view',
                'suppliers.suppliers.view', 'suppliers.suppliers.create', 'suppliers.suppliers.edit',
                'suppliers.purchase_quotes.view', 'suppliers.purchase_quotes.create', 'suppliers.purchase_quotes.edit', 'suppliers.purchase_quotes.delete', 'suppliers.purchase_quotes.convert',
                'suppliers.purchase_orders.view', 'suppliers.purchase_orders.create', 'suppliers.purchase_orders.edit', 'suppliers.purchase_orders.confirm', 'suppliers.purchase_orders.update_status',
                'suppliers.purchase_invoices.view', 'suppliers.purchase_invoices.record_payment',
                'suppliers.debit_notes.view', 'suppliers.debit_notes.create',
                'suppliers.supplier_ledger.view',
                'suppliers.supplier_payments.view',
            ],
        ],
        [
            'name' => 'Auditor',
            'label' => 'Auditor',
            'description' => 'Read-only, optionally time-boxed access across all modules.',
            'is_system' => true,
            'permissions' => 'read_only_plus_audit',
        ],
    ];

    public function run(): void
    {
        $all = Permissions::all();
        $readOnly = Permissions::readOnly();

        // Super Admin: setup-only permissions (company, branding, modules, users, roles, currencies, audit, backup).
        // No access to any operational module (sales, purchases, inventory, banking, accounting, etc.).
        $setup = [
            'dashboard.overview.view',
            // Company profile
            'settings.company.view',
            'settings.company.manage',
            // Branding (logo, favicon, colors, theme)
            'settings.branding.view',
            'settings.branding.manage',
            // Modules (enable/disable)
            'settings.modules.view',
            'settings.modules.manage',
            // Users & roles
            'settings.users.view',
            'settings.users.manage',
            'settings.roles.view',
            'settings.roles.manage',
            'settings.permissions.view',
            'settings.permissions.manage',
            // Currencies & base currency
            'settings.currencies.view',
            'settings.currencies.manage',
            'settings.base_currency.view',
            'settings.base_currency.manage',
            // Notifications
            'settings.notifications.view',
            'settings.notifications.manage',
            // Audit log
            'settings.audit.view',
            'settings.audit.export',
            // Backup & restore
            'settings.backup.view',
            'settings.backup.manage',
            // Mail server
            'settings.mail.view',
            'settings.mail.manage',
        ];

        // Per the master spec, Super Admin owns instance-level config: changing
        // the company profile (name, logo, favicon, base currency) and toggling
        // modules — Admin must never even see the Company Profile tab or reach
        // these routes, so both view and manage are excluded for Admin.
        $superAdminOnly = [
            'settings.company.view',
            'settings.company.manage',
            'settings.modules.view',
            'settings.modules.manage',
            'settings.backup.view',
            'settings.backup.manage',
            'settings.audit.view',
            'settings.audit.export',
            'settings.base_currency.view',
            'settings.base_currency.manage',
            'settings.mail.view',
            'settings.mail.manage',
        ];
        $admin = array_values(array_diff($all, $superAdminOnly));
        $auditor = array_values(array_unique(array_merge($readOnly, [
            'settings.audit.view',
            'settings.audit.export',
        ])));

        // Accounting / banking / cash flow / reports bundle.
        $accounting = [];
        foreach (['accounting', 'banking', 'cash_flow', 'reports'] as $module) {
            foreach (config("permissions.modules.{$module}.permissions", []) as $feature => $actions) {
                foreach ($actions as $action) {
                    $accounting[] = Permissions::key($module, $feature, $action);
                }
            }
        }
        $accounting = array_values(array_unique($accounting));
        $accounting[] = 'dashboard.overview.view';
        $accounting[] = 'sales.invoices.view';
        $accounting[] = 'sales.invoices.record_payment';
        $accounting[] = 'sales.statements.view';
        $accounting[] = 'sales.sales_payments.view';
        $accounting[] = 'sales.sales_payments.create';
        $accounting[] = 'suppliers.purchase_invoices.view';
        $accounting[] = 'suppliers.purchase_invoices.record_payment';
        $accounting[] = 'suppliers.supplier_payments.view';
        $accounting[] = 'suppliers.supplier_payments.create';
        $accounting[] = 'suppliers.supplier_ledger.view';

        foreach ($this->roles as $definition) {
            $role = Role::findOrCreate($definition['name'], 'web');

            $role->forceFill([
                'label' => $definition['label'],
                'description' => $definition['description'],
                'is_system' => $definition['is_system'],
            ])->save();

            $permissions = match ($definition['permissions']) {
                'all' => $all,
                'setup' => $setup,
                'admin' => $admin,
                'read_only' => $readOnly,
                'read_only_plus_audit' => $auditor,
                'accounting_banking' => $accounting,
                default => $definition['permissions'],
            };

            $permissionModels = Permission::whereIn('name', $permissions)->pluck('id')->all();
            $role->syncPermissions($permissionModels);
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
