<?php

/*
|--------------------------------------------------------------------------
| Module & Permission Registry
|--------------------------------------------------------------------------
|
| This is the single source of truth for every module and every permission
| in the application. Nothing client-specific is hardcoded in controllers
| or views — modules can be enabled/disabled, and roles are assigned
| permissions through the matrix UI, driven entirely from this file.
|
| A permission key is always  <module>.<feature>.<action>  e.g. sales.customers.view
*/

use App\Support\Permissions;

return [

    /*
    | The standard CRUD + export actions applied to every feature.
    */
    'actions' => ['view', 'create', 'edit', 'delete', 'export'],

    'modules' => [
        'dashboard' => [
            'label' => 'Dashboard',
            'icon' => 'dashboard',
            'core' => true,
            'default_enabled' => true,
            'description' => 'Overview, today\'s summary and activity feed.',
            'permissions' => [
                'overview' => ['view'],
            ],
        ],

        'reports' => [
            'label' => 'Reports',
            'icon' => 'reports',
            'core' => true,
            'default_enabled' => true,
            'description' => 'Financial, sales, inventory and custom reports.',
            'permissions' => [
                'reports' => ['view', 'export'],
                'custom_builder' => ['view', 'create', 'edit', 'delete'],
            ],
        ],

        'settings' => [
            'label' => 'Settings',
            'icon' => 'settings',
            'core' => true,
            'default_enabled' => true,
            'description' => 'Company profile, branding, modules, users, roles, themes and more.',
            'permissions' => [
                'company' => ['view', 'manage'],
                'branding' => ['view', 'manage'],
                'modules' => ['view', 'manage'],
                'users' => ['view', 'manage'],
                'roles' => ['view', 'manage'],
                'permissions' => ['view', 'manage'],
                'theme' => ['view', 'manage'],
                'templates' => ['view', 'manage'],
                'tax_rates' => ['view', 'manage'],
                'currencies' => ['view', 'manage'],
                'base_currency' => ['view', 'manage'],
                'payment_terms' => ['view', 'manage'],
                'discount_rules' => ['view', 'manage'],
                'branches' => ['view', 'manage'],
                'notifications' => ['view', 'manage'],
                'backup' => ['view', 'manage'],
                'audit' => ['view', 'export'],
                'mail' => ['view', 'manage'],
            ],
        ],

        'catalog' => [
            'label' => 'Catalog',
            'icon' => 'catalog',
            'core' => false,
            'default_enabled' => true,
            'description' => 'Products, brands, categories and price lists.',
            'permissions' => [
                'products' => ['view', 'create', 'edit', 'delete', 'export'],
                'brands' => ['view', 'create', 'edit', 'delete', 'export'],
                'categories' => ['view', 'create', 'edit', 'delete', 'export'],
                'price_lists' => ['view', 'create', 'edit', 'delete', 'export'],
            ],
        ],

        'sales' => [
            'label' => 'Sales',
            'icon' => 'sales',
            'core' => false,
            'default_enabled' => true,
            'description' => 'Customers, quotes, orders, invoices, credit notes and delivery.',
            'permissions' => [
                'customers' => ['view', 'create', 'edit', 'delete', 'export'],
                'quotes' => ['view', 'create', 'edit', 'delete', 'export', 'convert'],
                'orders' => ['view', 'create', 'edit', 'delete', 'export', 'confirm', 'update_status'],
                'invoices' => ['view', 'create', 'edit', 'delete', 'export', 'record_payment'],
                'sales_payments' => ['view', 'create', 'edit', 'delete', 'export'],
                'recurring_invoices' => ['view', 'create', 'edit', 'delete', 'export'],
                'credit_notes' => ['view', 'create', 'edit', 'delete', 'export'],
                'delivery_notes' => ['view', 'create', 'edit', 'delete', 'export', 'update_status'],
                'tracking' => ['view', 'update_status', 'export'],
                'withholding_tax_receipts' => ['view', 'create', 'edit', 'delete', 'export'],
                'statements' => ['view', 'export'],
            ],
        ],

        'suppliers' => [
            'label' => 'Suppliers',
            'icon' => 'suppliers',
            'core' => false,
            'default_enabled' => true,
            'description' => 'Supplier management and the full purchase side of the business.',
            'permissions' => [
                'suppliers' => ['view', 'create', 'edit', 'delete', 'export'],
                'purchase_quotes' => ['view', 'create', 'edit', 'delete', 'export', 'convert'],
                'purchase_orders' => ['view', 'create', 'edit', 'delete', 'export', 'confirm', 'update_status'],
                'purchase_invoices' => ['view', 'create', 'edit', 'delete', 'export', 'record_payment'],
                'debit_notes' => ['view', 'create', 'edit', 'delete', 'export'],
                'supplier_ledger' => ['view', 'export'],
                'supplier_payments' => ['view', 'create', 'edit', 'delete', 'export'],
            ],
        ],

        'inventory' => [
            'label' => 'Inventory',
            'icon' => 'inventory',
            'core' => false,
            'default_enabled' => true,
            'description' => 'Stock items, transfers, write-offs, production and warehouses.',
            'permissions' => [
                'items' => ['view', 'create', 'edit', 'delete', 'export', 'adjust_stock'],
                'transfers' => ['view', 'create', 'edit', 'delete', 'export'],
                'write_offs' => ['view', 'create', 'edit', 'delete', 'export'],
                'production_orders' => ['view', 'create', 'edit', 'delete', 'export', 'update_status'],
                'bill_of_materials' => ['view', 'create', 'edit', 'delete', 'export'],
                'warehouses' => ['view', 'create', 'edit', 'delete', 'export'],
                'incoming_shipments' => ['view', 'create', 'edit', 'delete', 'export', 'receive', 'approve'],
            ],
        ],

        'banking' => [
            'label' => 'Banking',
            'icon' => 'banking',
            'core' => false,
            'default_enabled' => true,
            'description' => 'Bank and cash accounts, transactions and reconciliations.',
            'permissions' => [
                'accounts' => ['view', 'create', 'edit', 'delete', 'export'],
                'transactions' => ['view', 'create', 'edit', 'delete', 'export'],
                'transfers' => ['view', 'create', 'edit', 'delete', 'export'],
                'reconciliations' => ['view', 'create', 'edit', 'delete', 'export'],
            ],
        ],

        'cash_flow' => [
            'label' => 'Cash Flow',
            'icon' => 'cashflow',
            'core' => false,
            'default_enabled' => true,
            'description' => 'Overview, forecast and detailed cash in/out reports.',
            'permissions' => [
                'overview' => ['view', 'export'],
                'inflows' => ['view', 'export'],
                'outflows' => ['view', 'export'],
                'forecast' => ['view', 'export'],
                'reports' => ['view', 'export'],
            ],
        ],

        'accounting' => [
            'label' => 'Accounting',
            'icon' => 'accounting',
            'core' => false,
            'default_enabled' => true,
            'description' => 'Chart of accounts, journals, bills, tax returns and budgeting.',
            'permissions' => [
                'chart_of_accounts' => ['view', 'create', 'edit', 'delete', 'export'],
                'journal_entries' => ['view', 'create', 'edit', 'delete', 'export'],
                'expense_claims' => ['view', 'create', 'edit', 'delete', 'export'],
                'bills' => ['view', 'create', 'edit', 'delete', 'export', 'record_payment'],
                'tax_returns' => ['view', 'create', 'edit', 'delete', 'export'],
                'budgeting' => ['view', 'create', 'edit', 'delete', 'export'],
            ],
        ],

        'employees' => [
            'label' => 'Employees',
            'icon' => 'employees',
            'core' => false,
            'default_enabled' => true,
            'description' => 'Employees, departments, attendance, salary structures and payroll.',
            'permissions' => [
                'employees' => ['view', 'create', 'edit', 'delete', 'export'],
                'departments' => ['view', 'create', 'edit', 'delete', 'export'],
                'attendance' => ['view', 'create', 'edit', 'delete', 'export', 'mark'],
                'my_attendance' => ['view', 'mark'],
                'salary_structures' => ['view', 'create', 'edit', 'delete', 'export'],
                'payroll_runs' => ['view', 'create', 'edit', 'delete', 'export', 'approve'],
                'documents' => ['view', 'create', 'edit', 'delete', 'export'],
            ],
        ],

        'visits' => [
            'label' => 'Visits',
            'icon' => 'visits',
            'core' => false,
            'default_enabled' => true,
            'description' => 'GPS-tracked customer visits with pit-stops and route history.',
            'permissions' => [
                'visits' => ['view', 'create', 'edit', 'delete', 'export'],
                'pit_stops' => ['view', 'create', 'edit', 'delete', 'export'],
                'map_view' => ['view', 'export'],
            ],
        ],

        'investments' => [
            'label' => 'Investments',
            'icon' => 'investments',
            'core' => false,
            'default_enabled' => true,
            'description' => 'Portfolio, transactions, returns, dividends and reports.',
            'permissions' => [
                'portfolio' => ['view', 'create', 'edit', 'delete', 'export'],
                'transactions' => ['view', 'create', 'edit', 'delete', 'export'],
                'returns' => ['view', 'export'],
                'dividends' => ['view', 'create', 'edit', 'delete', 'export'],
                'reports' => ['view', 'export'],
            ],
        ],

        'fixed_assets' => [
            'label' => 'Fixed Assets',
            'icon' => 'assets',
            'core' => false,
            'default_enabled' => true,
            'description' => 'Asset register, depreciation, disposals and reports.',
            'permissions' => [
                'assets' => ['view', 'create', 'edit', 'delete', 'export'],
                'depreciation' => ['view', 'run', 'export'],
                'disposals' => ['view', 'create', 'edit', 'delete', 'export'],
                'reports' => ['view', 'export'],
            ],
        ],

        'capital_accounts' => [
            'label' => 'Capital Accounts',
            'icon' => 'capital',
            'core' => false,
            'default_enabled' => true,
            'description' => 'Contributions, drawings, equity summary and statements.',
            'permissions' => [
                'contributions' => ['view', 'create', 'edit', 'delete', 'export'],
                'drawings' => ['view', 'create', 'edit', 'delete', 'export'],
                'equity' => ['view', 'export'],
                'statements' => ['view', 'export'],
            ],
        ],

        'pos' => [
            'label' => 'POS',
            'icon' => 'pos',
            'core' => false,
            'default_enabled' => true,
            'description' => 'Point of sale screen, shifts, receipts and till reconciliation.',
            'permissions' => [
                'sale_screen' => ['view', 'use'],
                'payment_methods' => ['view', 'create', 'edit', 'delete', 'export'],
                'shifts' => ['view', 'create', 'edit', 'export', 'open', 'close'],
                'receipts' => ['view', 'create', 'print', 'reprint'],
                'till_reconciliation' => ['view', 'create', 'export'],
            ],
        ],
    ],
];
