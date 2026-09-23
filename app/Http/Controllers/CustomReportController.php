<?php

namespace App\Http\Controllers;

use App\Models\CustomReport;
use App\Models\SalesInvoice;
use App\Models\InventoryStock;
use App\Models\AttendanceRecord;
use App\Models\BankTransaction;
use App\Models\PurchaseInvoice;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CustomReportController extends Controller
{
    public function index(): View
    {
        $reports = CustomReport::latest()->paginate(20);
        return view('reports.custom.index', compact('reports'));
    }

    public function create(Request $request): View
    {
        $fromReport = null;

        if ($request->filled('from')) {
            $fromReport = CustomReport::findOrFail($request->from);
        }

        return view('reports.custom.create', compact('fromReport'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'module' => 'required|string|max:50',
            'fields' => 'nullable',
            'filters' => 'nullable',
        ]);

        $data['fields'] = $this->payloadToArray($data['fields'] ?? null);
        $data['filters'] = $this->payloadToArray($data['filters'] ?? null);
        $data['user_id'] = auth()->id();

        CustomReport::create($data);

        return redirect()->route('reports.custom.index')->with('status', 'Report defined successfully.');
    }

    public function show(CustomReport $customReport): View
    {
        $moduleLabels = [
            'sales' => 'Sales Invoices',
            'inventory' => 'Inventory Stock',
            'employees' => 'Attendance Records',
            'banking' => 'Bank Transactions',
            'suppliers' => 'Purchase Invoices',
        ];

        $rows = $this->executeReport($customReport);

        return view('reports.custom.show', [
            'report' => $customReport,
            'rows' => $rows,
            'moduleLabel' => $moduleLabels[$customReport->module] ?? ucfirst($customReport->module),
        ]);
    }

    private function executeReport(CustomReport $customReport): array
    {
        $module = $customReport->module;
        $fields = $customReport->fields ?? [];
        $filters = $customReport->filters ?? [];

        $fieldMap = $this->fieldMap();
        $moduleMap = $fieldMap[$module] ?? [];

        $query = $this->baseQuery($module);

        foreach ($filters as $filter) {
            if (empty($filter['field']) || empty($filter['value'])) {
                continue;
            }

            $column = $moduleMap[$filter['field']]['column'] ?? null;
            $relation = $moduleMap[$filter['field']]['relation'] ?? null;

            if ($column) {
                $op = $filter['operator'] ?? 'equals';
                $value = $filter['value'];

                if ($relation) {
                    $query->whereHas($relation, function ($q) use ($column, $op, $value) {
                        $this->applyFilter($q, $column, $op, $value);
                    });
                } else {
                    $this->applyFilter($query, $column, $op, $value);
                }
            }
        }

        $results = $query->limit(200)->get();

        $rows = [];
        foreach ($results as $record) {
            $row = [];
            foreach ($fields as $fieldKey) {
                $def = $moduleMap[$fieldKey] ?? null;
                if (! $def) {
                    $row[$fieldKey] = null;
                    continue;
                }

                if (($def['relation'] ?? null) && $def['column']) {
                    $row[$fieldKey] = data_get($record, $def['relation'].'.'.$def['column'], null);
                } elseif ($def['column'] ?? null) {
                    $row[$fieldKey] = data_get($record, $def['column'], null);
                } else {
                    $row[$fieldKey] = null;
                }
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function baseQuery(string $module)
    {
        return match ($module) {
            'sales' => SalesInvoice::with('customer')->orderByDesc('issue_date'),
            'inventory' => InventoryStock::with('item.product', 'warehouse')->orderByDesc('id'),
            'employees' => AttendanceRecord::with('employee')->orderByDesc('attendance_date'),
            'banking' => BankTransaction::with('account')->orderByDesc('date'),
            'suppliers' => PurchaseInvoice::with('supplier')->orderByDesc('issue_date'),
            default => now()->model()->query(),
        };
    }

    private function applyFilter($query, string $column, string $op, string $value): void
    {
        match ($op) {
            'equals' => $query->where($column, $value),
            'not_equals' => $query->where($column, '!=', $value),
            'contains' => $query->where($column, 'like', "%{$value}%"),
            'gt' => $query->where($column, '>', $value),
            'lt' => $query->where($column, '<', $value),
            default => null,
        };
    }

    private function fieldMap(): array
    {
        return [
            'sales' => [
                'number'       => ['column' => 'number', 'label' => 'Invoice Number'],
                'customer'     => ['column' => 'name', 'relation' => 'customer', 'label' => 'Customer'],
                'issue_date'   => ['column' => 'issue_date', 'label' => 'Issue Date'],
                'due_date'     => ['column' => 'due_date', 'label' => 'Due Date'],
                'status'       => ['column' => 'status', 'label' => 'Status'],
                'subtotal'     => ['column' => 'subtotal', 'label' => 'Subtotal'],
                'tax_amount'   => ['column' => 'tax_amount', 'label' => 'Tax Amount'],
                'total'        => ['column' => 'total', 'label' => 'Total'],
                'paid_amount'  => ['column' => 'paid_amount', 'label' => 'Paid Amount'],
            ],
            'inventory' => [
                'name'          => ['column' => 'name', 'relation' => 'item.product', 'label' => 'Item Name'],
                'sku'           => ['column' => 'sku', 'relation' => 'item.product', 'label' => 'SKU'],
                'category'      => ['column' => 'category', 'relation' => 'item.product', 'label' => 'Category'],
                'warehouse'     => ['column' => 'name', 'relation' => 'warehouse', 'label' => 'Warehouse'],
                'stock_qty'     => ['column' => 'quantity', 'label' => 'Stock Quantity'],
                'unit_cost'     => ['column' => 'cost_price', 'relation' => 'item.product', 'label' => 'Unit Cost'],
                'reorder_level' => ['column' => 'reorder_level', 'relation' => 'item', 'label' => 'Reorder Level'],
            ],
            'employees' => [
                'name'            => ['column' => null, 'label' => 'Employee Name'],
                'department'      => ['column' => null, 'label' => 'Department'],
                'attendance_date' => ['column' => 'attendance_date', 'label' => 'Date'],
                'check_in_at'     => ['column' => 'check_in_at', 'label' => 'Check In'],
                'check_out_at'    => ['column' => 'check_out_at', 'label' => 'Check Out'],
                'status'          => ['column' => 'status', 'label' => 'Status'],
                'method'          => ['column' => 'method', 'label' => 'Method'],
            ],
            'banking' => [
                'account'      => ['column' => 'name', 'relation' => 'account', 'label' => 'Account'],
                'date'         => ['column' => 'date', 'label' => 'Date'],
                'description'  => ['column' => 'description', 'label' => 'Description'],
                'type'         => ['column' => 'type', 'label' => 'Type'],
                'amount'       => ['column' => 'amount', 'label' => 'Amount'],
                'reconciled'   => ['column' => 'is_reconciled', 'label' => 'Reconciled'],
            ],
            'suppliers' => [
                'company_name'   => ['column' => 'name', 'relation' => 'supplier', 'label' => 'Supplier Name'],
                'invoice_number' => ['column' => 'number', 'label' => 'Invoice Number'],
                'issue_date'     => ['column' => 'issue_date', 'label' => 'Issue Date'],
                'status'         => ['column' => 'status', 'label' => 'Status'],
                'total'          => ['column' => 'total', 'label' => 'Total'],
                'paid_amount'    => ['column' => 'paid_amount', 'label' => 'Paid Amount'],
            ],
        ];
    }

    private function payloadToArray(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }
}
