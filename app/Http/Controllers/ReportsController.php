<?php

namespace App\Http\Controllers;

class ReportsController extends Controller
{
    public function index()
    {
        $groups = [
            ['key' => 'financial', 'label' => 'Financial Reports', 'icon' => 'accounting', 'description' => 'Profit & loss, balance sheet, trial balance and general ledger.', 'reports' => ['Profit & Loss', 'Balance Sheet', 'Trial Balance', 'General Ledger']],
            ['key' => 'sales', 'label' => 'Sales Reports', 'icon' => 'sales', 'description' => 'Revenue, invoices, quotes and customer performance.', 'reports' => ['Revenue Summary', 'Invoice Aging', 'Quote Conversion', 'Top Customers']],
            ['key' => 'inventory', 'label' => 'Inventory Reports', 'icon' => 'inventory', 'description' => 'Stock levels, valuations, movements and reorder needs.', 'reports' => ['Stock on Hand', 'Inventory Valuation', 'Stock Movements', 'Reorder Alerts']],
            ['key' => 'hr', 'label' => 'HR Reports', 'icon' => 'employees', 'description' => 'Attendance, payroll and headcount analytics.', 'reports' => ['Attendance Summary', 'Payroll Summary', 'Headcount']],
            ['key' => 'assets', 'label' => 'Asset Reports', 'icon' => 'assets', 'description' => 'Asset register and depreciation schedules.', 'reports' => ['Asset Register', 'Depreciation Schedule']],
            ['key' => 'custom', 'label' => 'Custom Report Builder', 'icon' => 'reports', 'description' => 'Pick a data source, fields and filters — save for reuse.', 'reports' => [], 'custom' => true],
        ];

        return view('reports.index', compact('groups'));
    }
}
