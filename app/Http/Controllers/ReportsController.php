<?php

namespace App\Http\Controllers;

class ReportsController extends Controller
{
    public function index()
    {
        $groups = collect([
            ['key' => 'financial', 'label' => 'Financial Reports', 'icon' => 'accounting', 'route' => 'reports.financial', 'permission' => 'reports.reports.view', 'description' => 'Profit & loss, balance sheet, trial balance and general ledger from posted journal entries.', 'reports' => ['Profit & Loss', 'Balance Sheet', 'Trial Balance', 'General Ledger']],
            ['key' => 'sales', 'label' => 'Sales Reports', 'icon' => 'sales', 'route' => 'sales.reports.salesman', 'permission' => 'sales.reports.view', 'description' => 'Confirmed orders attributed to each salesman, with period filtering.', 'reports' => ['Sales by Salesman']],
            ['key' => 'inventory', 'label' => 'Inventory Reports', 'icon' => 'inventory', 'route' => 'reports.inventory', 'permission' => 'reports.reports.view', 'description' => 'Stock levels, valuations, movements and reorder needs.', 'reports' => ['Stock on Hand', 'Inventory Valuation', 'Stock Movements', 'Reorder Alerts']],
            ['key' => 'hr', 'label' => 'HR Reports', 'icon' => 'employees', 'route' => 'employees.attendance.report', 'permission' => 'employees.attendance.view', 'description' => 'Attendance summaries and working-time analytics.', 'reports' => ['Attendance Summary']],
            ['key' => 'assets', 'label' => 'Asset Reports', 'icon' => 'assets', 'route' => 'fixed_assets.reports.index', 'permission' => 'fixed_assets.reports.view', 'description' => 'Asset register, category summary and depreciation by period.', 'reports' => ['Asset Register', 'Category Summary', 'Depreciation by Period']],
            ['key' => 'cash_flow', 'label' => 'Cash Flow Reports', 'icon' => 'report', 'route' => 'cash_flow.reports', 'permission' => 'cash_flow.reports.view', 'description' => 'Statement of cash & bank showing opening, inflows, outflows and closing positions.', 'reports' => ['Cash Flow Statement']],
            ['key' => 'investments', 'label' => 'Investment Reports', 'icon' => 'chart', 'route' => 'investments.reports.index', 'permission' => 'investments.reports.view', 'description' => 'Portfolio valuation, allocation by type and dividend income.', 'reports' => ['Portfolio Summary', 'Allocation by Type', 'Dividends by Year']],
            ['key' => 'custom', 'label' => 'Custom Report Builder', 'icon' => 'reports', 'description' => 'Pick a data source, fields and filters — save for reuse.', 'reports' => [], 'custom' => true],
        ])->filter(function (array $group) {
            if (($group['custom'] ?? false) || empty($group['permission'])) {
                return true;
            }

            return auth()->user()?->can($group['permission']) ?? false;
        })->values()->all();

        return view('reports.index', compact('groups'));
    }
}
