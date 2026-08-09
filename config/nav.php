<?php

/*
|--------------------------------------------------------------------------
| Sidebar Navigation Registry
|--------------------------------------------------------------------------
|
| Config-driven navigation. Every item belongs to a module; the sidebar
| only renders it when that module is enabled and the current user holds
| the required permission. Adding a new module = adding an entry here.
*/

return [
    'items' => [
        [
            'label' => 'Dashboard',
            'route' => 'dashboard',
            'icon' => 'dashboard',
            'module' => 'dashboard',
            'permission' => 'dashboard.overview.view',
        ],

        [
            'label' => 'Reports',
            'route' => 'reports.index',
            'icon' => 'reports',
            'module' => 'reports',
            'permission' => 'reports.reports.view',
        ],

        [
            'group' => 'Catalog',
            'module' => 'catalog',
            'items' => [
                ['label' => 'Products', 'route' => 'catalog.products.index', 'icon' => 'package', 'permission' => 'catalog.products.view'],
                ['label' => 'Brands', 'route' => 'catalog.brands.index', 'icon' => 'tag', 'permission' => 'catalog.brands.view'],
                ['label' => 'Categories', 'route' => 'catalog.categories.index', 'icon' => 'document', 'permission' => 'catalog.categories.view'],
                ['label' => 'Price Lists', 'route' => 'catalog.price_lists.index', 'icon' => 'money', 'permission' => 'catalog.price_lists.view'],
            ],
        ],
        [
            'group' => 'Sales',
            'module' => 'sales',
            'items' => [
                ['label' => 'Customers', 'route' => 'sales.customers.index', 'icon' => 'users', 'permission' => 'sales.customers.view'],
                ['label' => 'Quotes', 'route' => 'sales.quotes.index', 'icon' => 'document', 'permission' => 'sales.quotes.view'],
                ['label' => 'Orders', 'route' => 'sales.orders.index', 'icon' => 'orders', 'permission' => 'sales.orders.view'],
                ['label' => 'Invoices', 'route' => 'sales.invoices.index', 'icon' => 'invoice', 'permission' => 'sales.invoices.view'],
                ['label' => 'Credit Notes', 'route' => 'sales.credit_notes.index', 'icon' => 'credit', 'permission' => 'sales.credit_notes.view'],
                ['label' => 'Delivery Notes', 'route' => 'sales.delivery_notes.index', 'icon' => 'truck', 'permission' => 'sales.delivery_notes.view'],
                ['label' => 'Recurring Invoices', 'route' => 'sales.recurring_invoices.index', 'icon' => 'repeat', 'permission' => 'sales.recurring_invoices.view'],
                ['label' => 'Tracking', 'route' => 'sales.tracking.index', 'icon' => 'location', 'permission' => 'sales.tracking.view'],
                ['label' => 'Withholding Tax', 'route' => 'sales.withholding_tax_receipts.index', 'icon' => 'tax', 'permission' => 'sales.withholding_tax_receipts.view'],
                ['label' => 'Statements', 'route' => 'sales.statements.index', 'icon' => 'report', 'permission' => 'sales.statements.view'],
            ],
        ],
        [
            'group' => 'Inventory',
            'module' => 'inventory',
            'items' => [
                ['label' => 'Items', 'route' => 'inventory.items.index', 'icon' => 'package', 'permission' => 'inventory.items.view'],
                ['label' => 'Transfers', 'route' => 'inventory.transfers.index', 'icon' => 'arrow-right', 'permission' => 'inventory.transfers.view'],
                ['label' => 'Write-Offs', 'route' => 'inventory.write_offs.index', 'icon' => 'trash', 'permission' => 'inventory.write_offs.view'],
                ['label' => 'Production Orders', 'route' => 'inventory.production_orders.index', 'icon' => 'zap', 'permission' => 'inventory.production_orders.view'],
                ['label' => 'Bill of Materials', 'route' => 'inventory.bill_of_materials.index', 'icon' => 'document', 'permission' => 'inventory.bill_of_materials.view'],
                ['label' => 'Warehouses', 'route' => 'inventory.warehouses.index', 'icon' => 'building', 'permission' => 'inventory.warehouses.view'],
            ],
        ],
        [
            'group' => 'Purchasing',
            'module' => 'suppliers',
            'items' => [
                ['label' => 'Suppliers', 'route' => 'suppliers.suppliers.index', 'icon' => 'suppliers', 'permission' => 'suppliers.suppliers.view'],
                ['label' => 'Purchase Quotes', 'route' => 'suppliers.purchase_quotes.index', 'icon' => 'document', 'permission' => 'suppliers.purchase_quotes.view'],
                ['label' => 'Purchase Orders', 'route' => 'suppliers.purchase_orders.index', 'icon' => 'orders', 'permission' => 'suppliers.purchase_orders.view'],
                ['label' => 'Purchase Invoices', 'route' => 'suppliers.purchase_invoices.index', 'icon' => 'invoice', 'permission' => 'suppliers.purchase_invoices.view'],
                ['label' => 'Debit Notes', 'route' => 'suppliers.debit_notes.index', 'icon' => 'credit', 'permission' => 'suppliers.debit_notes.view'],
                ['label' => 'Supplier Ledger', 'route' => 'suppliers.supplier_ledger.index', 'icon' => 'report', 'permission' => 'suppliers.supplier_ledger.view'],
                ['label' => 'Supplier Payments', 'route' => 'suppliers.supplier_payments.index', 'icon' => 'money', 'permission' => 'suppliers.supplier_payments.view'],
            ],
        ],
        [
            'group' => 'Banking',
            'module' => 'banking',
            'items' => [
                ['label' => 'Bank Accounts', 'route' => 'banking.accounts.index', 'icon' => 'banking', 'permission' => 'banking.accounts.view'],
                ['label' => 'Transactions', 'route' => 'banking.transactions.index', 'icon' => 'money', 'permission' => 'banking.transactions.view'],
                ['label' => 'Transfers', 'route' => 'banking.transfers.index', 'icon' => 'arrow-right', 'permission' => 'banking.transfers.view'],
                ['label' => 'Reconciliations', 'route' => 'banking.reconciliations.index', 'icon' => 'check-circle', 'permission' => 'banking.reconciliations.view'],
            ],
        ],
        [
            'group' => 'Cash Flow',
            'module' => 'cash_flow',
            'items' => [
                ['label' => 'Overview', 'route' => 'cash_flow.overview', 'icon' => 'cashflow', 'permission' => 'cash_flow.overview.view'],
                ['label' => 'Inflows', 'route' => 'cash_flow.inflows', 'icon' => 'arrow-down', 'permission' => 'cash_flow.inflows.view'],
                ['label' => 'Outflows', 'route' => 'cash_flow.outflows', 'icon' => 'arrow-up', 'permission' => 'cash_flow.outflows.view'],
                ['label' => 'Forecast', 'route' => 'cash_flow.forecast', 'icon' => 'chart', 'permission' => 'cash_flow.forecast.view'],
                ['label' => 'Reports', 'route' => 'cash_flow.reports', 'icon' => 'report', 'permission' => 'cash_flow.reports.view'],
            ],
        ],
        [
            'group' => 'Accounting',
            'module' => 'accounting',
            'items' => [
                ['label' => 'Chart of accounts', 'route' => 'accounting.accounts.index', 'icon' => 'database', 'permission' => 'accounting.chart_of_accounts.view'],
                ['label' => 'Journal', 'route' => 'accounting.journal.index', 'icon' => 'document', 'permission' => 'accounting.journal_entries.view'],
                ['label' => 'Expense claims', 'route' => 'accounting.expense_claims.index', 'icon' => 'money', 'permission' => 'accounting.expense_claims.view'],
                ['label' => 'Bills', 'route' => 'accounting.bills.index', 'icon' => 'invoice', 'permission' => 'accounting.bills.view'],
                ['label' => 'Tax returns', 'route' => 'accounting.tax_returns.index', 'icon' => 'tax', 'permission' => 'accounting.tax_returns.view'],
                ['label' => 'Budgets', 'route' => 'accounting.budgets.index', 'icon' => 'chart', 'permission' => 'accounting.budgeting.view'],
            ],
        ],
        [
            'group' => 'Employees',
            'module' => 'employees',
            'items' => [
                ['label' => 'Employees', 'route' => 'employees.employees.index', 'icon' => 'employees', 'permission' => 'employees.employees.view'],
                ['label' => 'Departments', 'route' => 'employees.departments.index', 'icon' => 'building', 'permission' => 'employees.departments.view'],
                ['label' => 'Attendance', 'route' => 'employees.attendance.index', 'icon' => 'clock', 'permission' => 'employees.attendance.view'],
                ['label' => 'Attendance Reports', 'route' => 'employees.attendance.report', 'icon' => 'reports', 'permission' => 'employees.attendance.view'],
                ['label' => 'Salary Structures', 'route' => 'employees.salary_structures.index', 'icon' => 'money', 'permission' => 'employees.salary_structures.view'],
                ['label' => 'Payroll', 'route' => 'employees.payroll.index', 'icon' => 'document', 'permission' => 'employees.payroll_runs.view'],
            ],
        ],
        [
            'group' => 'Visits',
            'module' => 'visits',
            'items' => [
                ['label' => 'Visits', 'route' => 'visits.index', 'icon' => 'visits', 'permission' => 'visits.visits.view'],
                ['label' => 'Map View', 'route' => 'visits.map', 'icon' => 'map', 'permission' => 'visits.map_view.view'],
            ],
        ],
        [
            'group' => 'Capital Accounts',
            'module' => 'capital_accounts',
            'items' => [
                ['label' => 'Contributions', 'route' => 'capital.contributions.index', 'icon' => 'money', 'permission' => 'capital_accounts.contributions.view'],
                ['label' => 'Drawings', 'route' => 'capital.drawings.index', 'icon' => 'arrow-right', 'permission' => 'capital_accounts.drawings.view'],
                ['label' => 'Equity', 'route' => 'capital.equity.index', 'icon' => 'chart', 'permission' => 'capital_accounts.equity.view'],
                ['label' => 'Statements', 'route' => 'capital.statements.index', 'icon' => 'report', 'permission' => 'capital_accounts.statements.view'],
            ],
        ],
        [
            'group' => 'Fixed Assets',
            'module' => 'fixed_assets',
            'items' => [
                ['label' => 'Assets', 'route' => 'fixed_assets.assets.index', 'icon' => 'assets', 'permission' => 'fixed_assets.assets.view'],
                ['label' => 'Depreciation', 'route' => 'fixed_assets.depreciation.index', 'icon' => 'clock', 'permission' => 'fixed_assets.depreciation.view'],
                ['label' => 'Disposals', 'route' => 'fixed_assets.disposals.index', 'icon' => 'arrow-right', 'permission' => 'fixed_assets.disposals.view'],
                ['label' => 'Reports', 'route' => 'fixed_assets.reports.index', 'icon' => 'report', 'permission' => 'fixed_assets.reports.view'],
            ],
        ],
        [
            'group' => 'Investments',
            'module' => 'investments',
            'items' => [
                ['label' => 'Portfolio', 'route' => 'investments.portfolio.index', 'icon' => 'investments', 'permission' => 'investments.portfolio.view'],
                ['label' => 'Transactions', 'route' => 'investments.transactions.index', 'icon' => 'money', 'permission' => 'investments.transactions.view'],
                ['label' => 'Returns', 'route' => 'investments.returns.index', 'icon' => 'chart', 'permission' => 'investments.returns.view'],
                ['label' => 'Dividends', 'route' => 'investments.dividends.index', 'icon' => 'document', 'permission' => 'investments.dividends.view'],
                ['label' => 'Reports', 'route' => 'investments.reports.index', 'icon' => 'report', 'permission' => 'investments.reports.view'],
            ],
        ],
        [
            'group' => 'POS',
            'module' => 'pos',
            'items' => [
                ['label' => 'Sale Screen', 'route' => 'pos.sale_screen.index', 'icon' => 'pos', 'permission' => 'pos.sale_screen.view'],
                ['label' => 'Payment Methods', 'route' => 'pos.payment_methods.index', 'icon' => 'money', 'permission' => 'pos.payment_methods.view'],
                ['label' => 'Shifts', 'route' => 'pos.shifts.index', 'icon' => 'clock', 'permission' => 'pos.shifts.view'],
                ['label' => 'Receipts', 'route' => 'pos.receipts.index', 'icon' => 'document', 'permission' => 'pos.receipts.view'],
                ['label' => 'Till Reconciliation', 'route' => 'pos.reconciliations.index', 'icon' => 'check-circle', 'permission' => 'pos.till_reconciliation.view'],
            ],
        ],
        [
            'group' => 'Administration',
            'module' => 'settings',
            'permission' => 'settings.company.view',
            'items' => [
                ['label' => 'Company Profile', 'route' => 'settings.company', 'icon' => 'company', 'permission' => 'settings.company.view'],
                ['label' => 'Modules', 'route' => 'settings.modules', 'icon' => 'modules', 'permission' => 'settings.modules.view'],
                ['label' => 'Notification Rules', 'route' => 'settings.notification-rules', 'icon' => 'bell', 'permission' => 'settings.notifications.view'],
                ['label' => 'Users', 'route' => 'settings.users.index', 'icon' => 'users', 'permission' => 'settings.users.view'],
                ['label' => 'Roles & Permissions', 'route' => 'settings.roles.index', 'icon' => 'roles', 'permission' => 'settings.roles.view'],
                ['label' => 'Audit Log', 'route' => 'settings.audit-log', 'icon' => 'audit', 'permission' => 'settings.audit.view'],
            ],
        ],
    ],
];
