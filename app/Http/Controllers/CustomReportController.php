<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\BankTransaction;
use App\Models\CustomReport;
use App\Models\InventoryStock;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CustomReportController extends Controller
{
    /**
     * Permission that grants visibility over every saved report, not just the
     * viewer's own. Held by the supervisory roles that manage the builder.
     */
    private const MANAGE_ALL = 'reports.custom_builder.edit';

    public function index(): View
    {
        $reports = CustomReport::query()
            ->when(! auth()->user()?->can(self::MANAGE_ALL), fn ($q) => $q->where('user_id', auth()->id()))
            ->latest()
            ->paginate(20);

        return view('reports.custom.index', compact('reports'));
    }

    public function create(Request $request): View
    {
        $fromReport = null;

        if ($request->filled('from')) {
            $fromReport = $this->findVisible($request->from);
        }

        return view('reports.custom.create', compact('fromReport'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'module' => ['required', 'string', 'max:50', Rule::in(array_keys($this->fieldMap()))],
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
        $this->assertVisible($customReport);

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

    /**
     * A saved report is owned by the user who defined it. `reports.reports.view`
     * is a broad permission held by HR, Salesman, Inventory Manager and
     * Accountant alike, so the route middleware alone would let any of them open
     * anyone else's report definition. Scope to the owner, and only the builder
     * editors may see across users.
     */
    private function assertVisible(CustomReport $report): void
    {
        abort_unless(
            $report->user_id === auth()->id() || auth()->user()?->can(self::MANAGE_ALL),
            403,
        );
    }

    private function findVisible(mixed $id): CustomReport
    {
        $report = CustomReport::findOrFail($id);

        $this->assertVisible($report);

        return $report;
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

            $def = $moduleMap[$filter['field']] ?? [];
            $column = $def['column'] ?? null;
            $relation = $def['relation'] ?? null;

            if ($column) {
                $op = $filter['operator'] ?? 'equals';
                $value = $filter['value'];

                if ($relation) {
                    $query->whereHas($relation, function ($q) use ($column, $op, $value, $def) {
                        if (($def['concat'] ?? null) !== null) {
                            // Composite name column: match either part (contains semantics).
                            $q->where($column, 'like', "%{$value}%")
                                ->orWhere($def['concat'], 'like', "%{$value}%");
                        } else {
                            $this->applyFilter($q, $column, $op, $value);
                        }
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
                    $first = (string) data_get($record, $def['relation'].'.'.$def['column'], '');

                    if (($def['concat'] ?? null) !== null) {
                        $last = (string) data_get($record, $def['relation'].'.'.$def['concat'], '');
                        $row[$fieldKey] = trim($first.' '.$last) !== '' ? trim($first.' '.$last) : null;
                    } else {
                        $row[$fieldKey] = $first !== '' ? $first : null;
                    }
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
            default => CustomReport::query()->whereRaw('1 = 0'),
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
                'number' => ['column' => 'number', 'label' => 'Invoice Number'],
                'customer' => ['column' => 'name', 'relation' => 'customer', 'label' => 'Customer'],
                'issue_date' => ['column' => 'issue_date', 'label' => 'Issue Date'],
                'due_date' => ['column' => 'due_date', 'label' => 'Due Date'],
                'status' => ['column' => 'status', 'label' => 'Status'],
                'subtotal' => ['column' => 'subtotal', 'label' => 'Subtotal'],
                'tax_amount' => ['column' => 'tax_amount', 'label' => 'Tax Amount'],
                'total' => ['column' => 'total', 'label' => 'Total'],
                'paid_amount' => ['column' => 'paid_amount', 'label' => 'Paid Amount'],
            ],
            'inventory' => [
                'name' => ['column' => 'name', 'relation' => 'item.product', 'label' => 'Item Name'],
                'sku' => ['column' => 'sku', 'relation' => 'item.product', 'label' => 'SKU'],
                'category' => ['column' => 'category', 'relation' => 'item.product', 'label' => 'Category'],
                'warehouse' => ['column' => 'name', 'relation' => 'warehouse', 'label' => 'Warehouse'],
                'stock_qty' => ['column' => 'quantity', 'label' => 'Stock Quantity'],
                'unit_cost' => ['column' => 'cost_price', 'relation' => 'item.product', 'label' => 'Unit Cost'],
                'reorder_level' => ['column' => 'reorder_level', 'relation' => 'item', 'label' => 'Reorder Level'],
            ],
            'employees' => [
                'name' => ['column' => 'first_name', 'relation' => 'employee', 'concat' => 'last_name', 'label' => 'Employee Name'],
                'department' => ['column' => 'name', 'relation' => 'employee.department', 'label' => 'Department'],
                'attendance_date' => ['column' => 'attendance_date', 'label' => 'Date'],
                'check_in_at' => ['column' => 'check_in_at', 'label' => 'Check In'],
                'check_out_at' => ['column' => 'check_out_at', 'label' => 'Check Out'],
                'status' => ['column' => 'status', 'label' => 'Status'],
                'method' => ['column' => 'method', 'label' => 'Method'],
            ],
            'banking' => [
                'account' => ['column' => 'name', 'relation' => 'account', 'label' => 'Account'],
                'date' => ['column' => 'date', 'label' => 'Date'],
                'description' => ['column' => 'description', 'label' => 'Description'],
                'type' => ['column' => 'type', 'label' => 'Type'],
                'amount' => ['column' => 'amount', 'label' => 'Amount'],
                'reconciled' => ['column' => 'is_reconciled', 'label' => 'Reconciled'],
            ],
            'suppliers' => [
                'company_name' => ['column' => 'name', 'relation' => 'supplier', 'label' => 'Supplier Name'],
                'invoice_number' => ['column' => 'number', 'label' => 'Invoice Number'],
                'issue_date' => ['column' => 'issue_date', 'label' => 'Issue Date'],
                'status' => ['column' => 'status', 'label' => 'Status'],
                'total' => ['column' => 'total', 'label' => 'Total'],
                'paid_amount' => ['column' => 'paid_amount', 'label' => 'Paid Amount'],
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
