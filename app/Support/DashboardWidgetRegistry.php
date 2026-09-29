<?php

namespace App\Support;

use App\Models\Role;

class DashboardWidgetRegistry
{
    public static function definitions(): array
    {
        return [
            'company_snapshot' => [
                'label' => 'Company snapshot', 'description' => 'Users, roles and active modules.', 'icon' => 'building', 'section' => 'Overview', 'permission' => 'settings.modules.view', 'type' => 'metrics', 'roles' => ['Super Admin', 'Admin'],
            ],
            'executive_summary' => [
                'label' => 'Executive summary', 'description' => 'A quick view of operating performance.', 'icon' => 'chart', 'section' => 'Overview', 'permission' => 'reports.reports.view', 'type' => 'metrics', 'roles' => ['Admin', 'HR', 'Accountant'],
            ],
            'sales_performance' => [
                'label' => 'Sales performance', 'description' => 'Invoiced revenue for the current month.', 'icon' => 'sales', 'section' => 'Commercial', 'permission' => 'sales.invoices.view', 'type' => 'metrics', 'roles' => ['Admin', 'HR', 'Salesman', 'Inventory Manager'],
            ],
            'receivables' => [
                'label' => 'Receivables', 'description' => 'Outstanding customer balances and overdue invoices.', 'icon' => 'money', 'section' => 'Commercial', 'permission' => 'sales.invoices.view', 'type' => 'metrics', 'roles' => ['Admin', 'HR', 'Salesman', 'Inventory Manager'],
            ],
            'sales_pipeline' => [
                'label' => 'Sales pipeline', 'description' => 'Orders that still need to move through fulfilment.', 'icon' => 'orders', 'section' => 'Commercial', 'permission' => 'sales.orders.view', 'type' => 'progress', 'roles' => ['Admin', 'HR', 'Salesman', 'Inventory Manager'],
            ],
            'sales_trend' => [
                'label' => 'Sales trend', 'description' => 'Invoiced and collected revenue over the last six months.', 'icon' => 'chart', 'section' => 'Commercial', 'permission' => 'sales.invoices.view', 'type' => 'chart', 'roles' => ['Admin', 'HR', 'Salesman', 'Inventory Manager'],
            ],
            'purchase_commitments' => [
                'label' => 'Purchase commitments', 'description' => 'Open purchase orders and expected deliveries.', 'icon' => 'suppliers', 'section' => 'Operations', 'permission' => 'suppliers.purchase_orders.view', 'type' => 'metrics', 'roles' => ['Admin', 'HR', 'Inventory Manager'],
            ],
            'supplier_payables' => [
                'label' => 'Supplier payables', 'description' => 'Open supplier invoices and due balances.', 'icon' => 'invoice', 'section' => 'Operations', 'permission' => 'suppliers.purchase_invoices.view', 'type' => 'metrics', 'roles' => ['Admin', 'HR', 'Inventory Manager'],
            ],
            'inventory_health' => [
                'label' => 'Inventory health', 'description' => 'Stock items requiring replenishment attention.', 'icon' => 'inventory', 'section' => 'Operations', 'permission' => 'inventory.items.view', 'type' => 'progress', 'roles' => ['Admin', 'HR', 'Salesman', 'Inventory Manager'],
            ],
            'cash_position' => [
                'label' => 'Cash position', 'description' => 'Available balance across bank and cash accounts.', 'icon' => 'banking', 'section' => 'Finance', 'permission' => 'banking.accounts.view', 'type' => 'metrics', 'roles' => ['Admin', 'HR', 'Accountant'],
            ],
            'cash_flow_trend' => [
                'label' => 'Cash flow trend', 'description' => 'Inflows and outflows over the last six months.', 'icon' => 'cashflow', 'section' => 'Finance', 'permission' => 'banking.transactions.view', 'type' => 'chart', 'roles' => ['Admin', 'HR', 'Accountant'],
            ],
            'banking_activity' => [
                'label' => 'Banking activity', 'description' => 'The latest deposits and withdrawals.', 'icon' => 'repeat', 'section' => 'Finance', 'permission' => 'banking.transactions.view', 'type' => 'list', 'roles' => ['Admin', 'HR', 'Accountant'],
            ],
            'accounting_summary' => [
                'label' => 'Accounting summary', 'description' => 'Journal posting status and recent entries.', 'icon' => 'accounting', 'section' => 'Finance', 'permission' => 'accounting.journal_entries.view', 'type' => 'list', 'roles' => ['Admin', 'HR', 'Accountant'],
            ],
            'people_overview' => [
                'label' => 'People overview', 'description' => 'Headcount and employment status at a glance.', 'icon' => 'employees', 'section' => 'People', 'permission' => 'employees.employees.view', 'type' => 'metrics', 'roles' => ['Admin', 'HR', 'Accountant'],
            ],
            'attendance_today' => [
                'label' => 'Attendance today', 'description' => 'Today’s attendance exceptions and completion.', 'icon' => 'clock', 'section' => 'People', 'permission' => 'employees.attendance.view', 'type' => 'progress', 'roles' => ['Admin', 'HR', 'Accountant'],
            ],
            'leave_pending' => [
                'label' => 'Leave requests', 'description' => 'Requests waiting for review.', 'icon' => 'calendar', 'section' => 'People', 'permission' => 'employees.leave_requests.view', 'type' => 'list', 'roles' => ['Admin', 'HR', 'Accountant'],
            ],
            'payroll_summary' => [
                'label' => 'Payroll summary', 'description' => 'The latest payroll run and its status.', 'icon' => 'money', 'section' => 'People', 'permission' => 'employees.payroll_runs.view', 'type' => 'metrics', 'roles' => ['Admin', 'HR', 'Accountant'],
            ],
            'my_attendance' => [
                'label' => 'My attendance', 'description' => 'Your latest attendance record.', 'icon' => 'clock', 'section' => 'My workspace', 'permission' => 'employees.my_attendance.view', 'type' => 'metrics', 'roles' => ['Admin', 'HR', 'Salesman', 'Inventory Manager', 'Employee'],
            ],
            'my_leave' => [
                'label' => 'My leave', 'description' => 'Your leave requests and balances.', 'icon' => 'calendar', 'section' => 'My workspace', 'permission' => 'employees.my_leave.view', 'type' => 'list', 'roles' => ['Admin', 'HR', 'Salesman', 'Inventory Manager', 'Employee'],
            ],
            'audit_activity' => [
                'label' => 'Recent activity', 'description' => 'Latest changes across the company.', 'icon' => 'activity', 'section' => 'Activity', 'permission' => 'settings.audit.view', 'type' => 'list', 'roles' => ['Super Admin', 'Admin'],
            ],
            'quick_actions' => [
                'label' => 'Quick actions', 'description' => 'Common actions available to your role.', 'icon' => 'zap', 'section' => 'My workspace', 'permission' => 'dashboard.overview.view', 'type' => 'actions', 'roles' => ['Super Admin', 'Admin', 'HR', 'Accountant', 'Salesman', 'Inventory Manager', 'Employee'],
            ],
        ];
    }

    public static function forRole(?Role $role): array
    {
        $definitions = self::definitions();

        if (! $role) {
            return $definitions;
        }

        return collect($definitions)
            ->filter(fn (array $definition): bool => in_array($role->name, $definition['roles'], true))
            ->all();
    }
}
