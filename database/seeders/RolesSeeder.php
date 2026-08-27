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
     * New 6-role structure replacing the old 8-role set.
     * Removed: Auditor, Accountant, Procurement.
     * Added:   Employee (generic staff attendance).
     */
    protected array $roles = [
        [
            'name' => 'Super Admin',
            'label' => 'Super Admin',
            'description' => 'Company configuration only — profile, branding, modules, base currency, backups and audit. No business data access.',
            'is_system' => true,
            'permissions' => 'super_admin',
        ],
        [
            'name' => 'Admin',
            'label' => 'Admin',
            'description' => 'Full CRUD + export across all enabled modules, plus staff and role management.',
            'is_system' => true,
            'permissions' => 'admin',
        ],
        [
            'name' => 'HR',
            'label' => 'HR',
            'description' => 'Read-only and export across all modules. Can create staff accounts for attendance purposes.',
            'is_system' => true,
            'permissions' => 'hr',
        ],
        [
            'name' => 'Salesman',
            'label' => 'Salesman',
            'description' => 'Manages customers, creates quotes/orders, GPS-tracked visits. Limited inventory visibility.',
            'is_system' => true,
            'permissions' => 'salesman',
        ],
        [
            'name' => 'Inventory Manager',
            'label' => 'Inventory Manager',
            'description' => 'Fulfills confirmed orders, creates invoices, manages product catalog, stock and warehouses.',
            'is_system' => true,
            'permissions' => 'inventory_manager',
        ],
        [
            'name' => 'Employee',
            'label' => 'Employee',
            'description' => 'Self-service attendance only. Default role for general staff who just need to clock in.',
            'is_system' => true,
            'permissions' => 'employee',
        ],
    ];

    public function run(): void
    {
        $all = Permissions::all();
        $readOnly = Permissions::readOnly();

        // ── 1. Super Admin ──────────────────────────────────────────────
        // Company configuration only. No business module data, no user/role management.
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
        ];

        // ── 2. Admin ───────────────────────────────────────────────────
        // Everything except Super-Admin-only settings.
        $superAdminSettings = [
            'settings.company.view', 'settings.company.manage',
            'settings.modules.view', 'settings.modules.manage',
            'settings.base_currency.view', 'settings.base_currency.manage',
            'settings.backup.view', 'settings.backup.manage',
            'settings.audit.view', 'settings.audit.export',
            'settings.mail.view', 'settings.mail.manage',
        ];
        $admin = array_values(array_diff($all, $superAdminSettings));

        // ── 3. HR ──────────────────────────────────────────────────────
        // Read-only + export across all modules, plus staff creation.
        $hr = array_values(array_unique(array_merge(
            $readOnly,
            ['settings.users.create'],
        )));

        // ── 4. Salesman ────────────────────────────────────────────────
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
            // Sales — tracking & statements
            'sales.tracking.view',
            'sales.statements.view',
            // Inventory (view only)
            'inventory.items.view',
            // Visits
            'visits.visits.view',
            'visits.visits.create',
            'visits.visits.edit',
            'visits.pit_stops.view',
            'visits.pit_stops.create',
            'visits.pit_stops.edit',
            // Attendance
            'employees.my_attendance.view',
            'employees.my_attendance.mark',
        ];

        // ── 5. Inventory Manager ───────────────────────────────────────
        $inventoryManager = [
            'dashboard.overview.view',
            'reports.reports.view',
            // Sales — orders (view + status)
            'sales.orders.view',
            'sales.orders.update_status',
            // Sales — invoices (create from confirmed orders)
            'sales.invoices.view',
            'sales.invoices.create',
            // Sales — delivery notes
            'sales.delivery_notes.view',
            'sales.delivery_notes.create',
            'sales.delivery_notes.edit',
            'sales.delivery_notes.update_status',
            // Sales — tracking
            'sales.tracking.view',
            'sales.tracking.update_status',
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
        ];

        // ── Cleanup: remove old roles no longer in the new set ─────────
        $newRoleNames = array_column($this->roles, 'name');
        $oldRoles = Role::whereNotIn('name', $newRoleNames)->get();
        foreach ($oldRoles as $oldRole) {
            // Unassign all users from this role before deleting
            $oldRole->users()->detach();
            $oldRole->delete();
        }

        // ── Seed each role ─────────────────────────────────────────────
        foreach ($this->roles as $definition) {
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
                'salesman' => $salesman,
                'inventory_manager' => $inventoryManager,
                'employee' => $employee,
                default => $definition['permissions'],
            };

            $permissionModels = Permission::whereIn('name', $permissions)->pluck('id')->all();
            $role->syncPermissions($permissionModels);
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
