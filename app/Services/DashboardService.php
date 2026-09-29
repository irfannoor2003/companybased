<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\DashboardWidgetPreference;
use App\Models\Department;
use App\Models\Employee;
use App\Models\InventoryItem;
use App\Models\JournalEntry;
use App\Models\LeaveRequest;
use App\Models\Module;
use App\Models\PayrollRun;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Support\DashboardWidgetRegistry;

class DashboardService
{
    public function forUser(User $user): array
    {
        $roleName = $user->getRoleNames()->first();
        $role = $roleName ? Role::query()->where('name', $roleName)->first() : null;
        $definitions = DashboardWidgetRegistry::forRole($role);
        $userPreferences = DashboardWidgetPreference::query()->where('user_id', $user->id)->orderBy('sort_order')->get()->keyBy('widget_key');
        $rolePreferences = $role?->dashboardWidgets()->get()->keyBy('widget_key');
        $preferences = $userPreferences->isNotEmpty() ? $userPreferences : $rolePreferences;

        $keys = $preferences?->isNotEmpty()
            ? $preferences->where('is_visible', true)->sortBy('sort_order')->keys()->all()
            : collect($definitions)->keys()->all();

        $widgets = collect($keys)
            ->map(fn (string $key): ?array => isset($definitions[$key]) ? array_merge($definitions[$key], ['key' => $key]) : null)
            ->filter()
            ->filter(fn (array $definition): bool => $user->can($definition['permission'])
                && Module::isEnabled(explode('.', $definition['permission'])[0]))
            ->map(function (array $definition) use ($user): array {
                return array_merge($definition, ['data' => $this->dataFor($definition['key'], $user)]);
            })
            ->values()
            ->all();

        return [
            'roleName' => $roleName ?: 'Team member',
            'roleLabel' => $role?->label ?: ($roleName ?: 'Team member'),
            'roleDescription' => $role?->description,
            'widgets' => $widgets,
        ];
    }

    private function dataFor(string $key, User $user): array
    {
        return match ($key) {
            'company_snapshot' => $this->companySnapshot(),
            'executive_summary' => $this->executiveSummary(),
            'sales_performance' => $this->salesPerformance(),
            'sales_trend' => $this->salesTrend(),
            'receivables' => $this->receivables(),
            'sales_pipeline' => $this->salesPipeline(),
            'purchase_commitments' => $this->purchaseCommitments(),
            'supplier_payables' => $this->supplierPayables(),
            'inventory_health' => $this->inventoryHealth(),
            'cash_position' => $this->cashPosition(),
            'cash_flow_trend' => $this->cashFlowTrend(),
            'banking_activity' => $this->bankingActivity(),
            'accounting_summary' => $this->accountingSummary(),
            'people_overview' => $this->peopleOverview(),
            'attendance_today' => $this->attendanceToday(),
            'leave_pending' => $this->leavePending(),
            'payroll_summary' => $this->payrollSummary(),
            'my_attendance' => $this->myAttendance($user),
            'my_leave' => $this->myLeave($user),
            'audit_activity' => $this->auditActivity(),
            'quick_actions' => $this->quickActions($user),
            default => [],
        };
    }

    private function companySnapshot(): array
    {
        $enabledModules = Module::query()->where('enabled', true)->count();

        return [
            'items' => [
                ['label' => 'Users', 'value' => number_format(User::query()->count()), 'tone' => 'primary'],
                ['label' => 'Roles', 'value' => number_format(Role::query()->where('name', '!=', config('roles.super_admin'))->count()), 'tone' => 'info'],
                ['label' => 'Enabled modules', 'value' => number_format($enabledModules), 'tone' => 'success'],
            ],
        ];
    }

    private function executiveSummary(): array
    {
        $month = now()->startOfMonth();
        $revenue = (float) SalesInvoice::query()->where('issue_date', '>=', $month)->sum('total');
        $receivable = (float) SalesInvoice::query()->whereIn('status', ['sent', 'partially_paid', 'overdue'])->sum('total') - (float) SalesInvoice::query()->whereIn('status', ['sent', 'partially_paid', 'overdue'])->sum('paid_amount');
        $cash = (float) BankAccount::query()->active()->sum('opening_balance');

        return [
            'items' => [
                ['label' => 'Revenue this month', 'value' => $this->money($revenue), 'tone' => 'primary'],
                ['label' => 'Receivables', 'value' => $this->money(max(0, $receivable)), 'tone' => 'warning'],
                ['label' => 'Cash accounts', 'value' => $this->money($cash), 'tone' => 'success'],
            ],
        ];
    }

    private function salesPerformance(): array
    {
        $month = now()->startOfMonth();
        $invoices = SalesInvoice::query()->where('issue_date', '>=', $month);
        $count = (clone $invoices)->count();
        $total = (float) (clone $invoices)->sum('total');
        $paid = (float) (clone $invoices)->sum('paid_amount');

        return [
            'items' => [
                ['label' => 'Invoiced', 'value' => $this->money($total), 'tone' => 'primary'],
                ['label' => 'Invoices', 'value' => number_format($count), 'tone' => 'info'],
                ['label' => 'Collected', 'value' => $this->money($paid), 'tone' => 'success'],
            ],
        ];
    }

    private function receivables(): array
    {
        $invoices = SalesInvoice::query()->whereIn('status', ['sent', 'partially_paid', 'overdue']);
        $total = (float) (clone $invoices)->sum('total');
        $paid = (float) (clone $invoices)->sum('paid_amount');
        $overdue = (clone $invoices)->whereDate('due_date', '<', now())->count();

        return [
            'items' => [
                ['label' => 'Outstanding', 'value' => $this->money(max(0, $total - $paid)), 'tone' => 'warning'],
                ['label' => 'Overdue invoices', 'value' => number_format($overdue), 'tone' => $overdue ? 'danger' : 'success'],
                ['label' => 'Open invoices', 'value' => number_format((clone $invoices)->count()), 'tone' => 'info'],
            ],
        ];
    }

    private function salesTrend(): array
    {
        $labels = [];
        $invoiced = [];
        $collected = [];

        for ($monthsAgo = 5; $monthsAgo >= 0; $monthsAgo--) {
            $start = now()->subMonthsNoOverflow($monthsAgo)->startOfMonth();
            $end = $start->copy()->endOfMonth();
            $invoices = SalesInvoice::query()->whereBetween('issue_date', [$start, $end]);
            $labels[] = $start->format('M Y');
            $invoiced[] = round((float) (clone $invoices)->sum('total'), 2);
            $collected[] = round((float) (clone $invoices)->sum('paid_amount'), 2);
        }

        return ['chart' => [
            'data' => ['labels' => $labels, 'datasets' => [
                ['label' => 'Invoiced', 'data' => $invoiced, 'borderColor' => '#4f46e5', 'backgroundColor' => 'rgba(79, 70, 229, 0.12)', 'fill' => true, 'tension' => 0.4],
                ['label' => 'Collected', 'data' => $collected, 'borderColor' => '#0ea5e9', 'backgroundColor' => 'rgba(14, 165, 233, 0.10)', 'fill' => true, 'tension' => 0.4],
            ]],
            'options' => ['responsive' => true, 'maintainAspectRatio' => false, 'plugins' => ['legend' => ['position' => 'bottom']], 'scales' => ['y' => ['beginAtZero' => true]]],
        ]];
    }

    private function cashFlowTrend(): array
    {
        $labels = [];
        $inflows = [];
        $outflows = [];

        for ($monthsAgo = 5; $monthsAgo >= 0; $monthsAgo--) {
            $start = now()->subMonthsNoOverflow($monthsAgo)->startOfMonth();
            $end = $start->copy()->endOfMonth();
            $labels[] = $start->format('M Y');
            $inflows[] = round((float) BankTransaction::query()->whereBetween('transaction_date', [$start, $end])->whereIn('type', ['deposit', 'transfer_in'])->sum('amount'), 2);
            $outflows[] = round((float) BankTransaction::query()->whereBetween('transaction_date', [$start, $end])->whereIn('type', ['withdrawal', 'transfer_out'])->sum('amount'), 2);
        }

        return ['chart' => [
            'data' => ['labels' => $labels, 'datasets' => [
                ['label' => 'Inflows', 'data' => $inflows, 'borderColor' => '#10b981', 'backgroundColor' => 'rgba(16, 185, 129, 0.12)', 'fill' => true, 'tension' => 0.4],
                ['label' => 'Outflows', 'data' => $outflows, 'borderColor' => '#f59e0b', 'backgroundColor' => 'rgba(245, 158, 11, 0.12)', 'fill' => true, 'tension' => 0.4],
            ]],
            'options' => ['responsive' => true, 'maintainAspectRatio' => false, 'plugins' => ['legend' => ['position' => 'bottom']], 'scales' => ['y' => ['beginAtZero' => true]]],
        ]];
    }

    private function salesPipeline(): array
    {
        $statuses = ['confirmed', 'packed', 'shipped'];
        $orders = SalesOrder::query()->whereIn('status', $statuses);
        $counts = SalesOrder::query()->whereIn('status', $statuses)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        return [
            'total' => number_format((float) (clone $orders)->sum('total')),
            'items' => collect($statuses)->map(fn (string $status): array => [
                'label' => ucfirst($status),
                'value' => number_format((int) ($counts[$status] ?? 0)),
                'tone' => $status === 'shipped' ? 'info' : 'primary',
            ])->all(),
        ];
    }

    private function purchaseCommitments(): array
    {
        $orders = PurchaseOrder::query()->whereNotIn('status', ['draft', 'cancelled', 'completed', 'received']);
        $awaiting = (clone $orders)->whereIn('status', ['confirmed', 'partial_received'])->count();

        return [
            'items' => [
                ['label' => 'Open commitments', 'value' => $this->money((float) (clone $orders)->sum('total')), 'tone' => 'primary'],
                ['label' => 'Awaiting receipt', 'value' => number_format($awaiting), 'tone' => 'warning'],
                ['label' => 'Suppliers', 'value' => number_format(Supplier::query()->count()), 'tone' => 'info'],
            ],
        ];
    }

    private function supplierPayables(): array
    {
        $invoices = PurchaseInvoice::query()->whereIn('status', ['sent', 'partially_paid', 'overdue']);
        $total = (float) (clone $invoices)->sum('total');
        $paid = (float) (clone $invoices)->sum('paid_amount');
        $overdue = (clone $invoices)->whereDate('due_date', '<', now())->count();

        return [
            'items' => [
                ['label' => 'Outstanding', 'value' => $this->money(max(0, $total - $paid)), 'tone' => 'warning'],
                ['label' => 'Overdue', 'value' => number_format($overdue), 'tone' => $overdue ? 'danger' : 'success'],
                ['label' => 'Open bills', 'value' => number_format((clone $invoices)->count()), 'tone' => 'info'],
            ],
        ];
    }

    private function inventoryHealth(): array
    {
        $items = InventoryItem::query()->active()->withSum('stock as on_hand', 'quantity')->get();
        $lowStock = $items->filter(fn (InventoryItem $item): bool => (float) $item->reorder_level > 0 && (float) $item->on_hand <= (float) $item->reorder_level)->count();
        $total = $items->count();

        return [
            'total' => number_format($total),
            'items' => [
                ['label' => 'Stock items', 'value' => number_format($total), 'tone' => 'primary'],
                ['label' => 'Low stock', 'value' => number_format($lowStock), 'tone' => $lowStock ? 'warning' : 'success'],
                ['label' => 'Healthy ratio', 'value' => $total ? number_format((($total - $lowStock) / $total) * 100).'%' : '—', 'tone' => 'info'],
            ],
        ];
    }

    private function cashPosition(): array
    {
        $opening = (float) BankAccount::query()->active()->sum('opening_balance');
        $in = (float) BankTransaction::query()->whereIn('type', ['deposit', 'transfer_in'])->sum('amount');
        $out = (float) BankTransaction::query()->whereIn('type', ['withdrawal', 'transfer_out'])->sum('amount');
        $accounts = BankAccount::query()->active()->count();

        return [
            'items' => [
                ['label' => 'Available cash', 'value' => $this->money($opening + $in - $out), 'tone' => 'primary'],
                ['label' => 'Money in', 'value' => $this->money($in), 'tone' => 'success'],
                ['label' => 'Money out', 'value' => $this->money($out), 'tone' => 'warning'],
                ['label' => 'Active accounts', 'value' => number_format($accounts), 'tone' => 'info'],
            ],
        ];
    }

    private function bankingActivity(): array
    {
        $items = BankTransaction::query()->with('account')->latest('transaction_date')->latest('id')->limit(6)->get()->map(fn (BankTransaction $transaction): array => [
            'title' => $transaction->description ?: $transaction->counterparty ?: 'Bank transaction',
            'meta' => ($transaction->account?->name ?: 'Account').' · '.($transaction->transaction_date?->format('d M Y') ?: '—'),
            'value' => ($transaction->isCredit() ? '+' : '-').$this->money((float) $transaction->amount),
            'tone' => $transaction->isCredit() ? 'success' : 'warning',
        ])->all();

        return ['items' => $items, 'href' => route('banking.transactions.index')];
    }

    private function accountingSummary(): array
    {
        $posted = JournalEntry::query()->where('status', 'posted')->count();
        $draft = JournalEntry::query()->where('status', 'draft')->count();
        $items = JournalEntry::query()->with('creator')->latest('entry_date')->latest('id')->limit(6)->get()->map(fn (JournalEntry $entry): array => [
            'title' => $entry->number.' · '.($entry->description ?: 'Journal entry'),
            'meta' => ($entry->entry_date?->format('d M Y') ?: '—').' · '.($entry->creator?->displayName() ?: 'System'),
            'value' => strtoupper($entry->status),
            'tone' => $entry->status === 'posted' ? 'success' : ($entry->status === 'void' ? 'danger' : 'warning'),
        ])->all();

        return ['items' => $items, 'metrics' => [['label' => 'Posted', 'value' => number_format($posted)], ['label' => 'Drafts', 'value' => number_format($draft)]], 'href' => route('accounting.journal.index')];
    }

    private function peopleOverview(): array
    {
        return [
            'items' => [
                ['label' => 'Active employees', 'value' => number_format(Employee::query()->active()->count()), 'tone' => 'primary'],
                ['label' => 'On leave', 'value' => number_format(Employee::query()->where('employment_status', 'on_leave')->count()), 'tone' => 'warning'],
                ['label' => 'Departments', 'value' => number_format(Department::query()->count()), 'tone' => 'info'],
            ],
        ];
    }

    private function attendanceToday(): array
    {
        $records = AttendanceRecord::query()->whereDate('attendance_date', now())->get();
        $present = $records->whereIn('status', ['present', 'late'])->count();
        $exceptions = $records->whereIn('status', ['late', 'short_leave', 'half_day', 'absent'])->count();

        return [
            'total' => number_format(Employee::query()->active()->count()),
            'items' => [
                ['label' => 'Marked', 'value' => number_format($records->count()), 'tone' => 'info'],
                ['label' => 'Present / late', 'value' => number_format($present), 'tone' => 'success'],
                ['label' => 'Exceptions', 'value' => number_format($exceptions), 'tone' => $exceptions ? 'warning' : 'success'],
            ],
        ];
    }

    private function leavePending(): array
    {
        $items = LeaveRequest::query()->with('employee')->where('status', 'pending')->latest()->limit(6)->get()->map(fn (LeaveRequest $request): array => [
            'title' => $request->employee?->fullName() ?: 'Employee',
            'meta' => $request->leave_type.' · '.($request->start_date?->format('d M Y') ?: '—'),
            'value' => $request->days.'d',
            'tone' => 'warning',
        ])->all();

        return ['items' => $items, 'metrics' => [['label' => 'Pending', 'value' => number_format(LeaveRequest::query()->where('status', 'pending')->count())]], 'href' => route('employees.leave.index')];
    }

    private function payrollSummary(): array
    {
        $run = PayrollRun::query()->latest('period_end')->latest('id')->first();

        return ['items' => [
            ['label' => 'Latest period', 'value' => $run?->period_end?->format('M Y') ?: '—', 'tone' => 'primary'],
            ['label' => 'Net payroll', 'value' => $run ? $this->money((float) $run->total_net) : '—', 'tone' => 'success'],
            ['label' => 'Status', 'value' => $run ? strtoupper($run->status) : '—', 'tone' => $run?->status === 'paid' ? 'success' : 'warning'],
        ], 'href' => route('employees.payroll.index')];
    }

    private function myAttendance(User $user): array
    {
        $record = $user->employee?->attendanceRecords()->whereDate('attendance_date', now())->latest('id')->first();

        return ['items' => [
            ['label' => 'Today', 'value' => $record ? strtoupper($record->status) : 'Not marked', 'tone' => $record?->status === 'present' ? 'success' : 'warning'],
            ['label' => 'Check in', 'value' => $record?->check_in_at?->format('g:i A') ?: '—', 'tone' => 'info'],
            ['label' => 'Check out', 'value' => $record?->check_out_at?->format('g:i A') ?: '—', 'tone' => 'info'],
        ], 'href' => route('employees.my_attendance.index')];
    }

    private function myLeave(User $user): array
    {
        $requests = $user->employee?->leaveRequests()->latest('start_date')->limit(5)->get() ?? collect();
        $items = $requests->map(fn (LeaveRequest $request): array => [
            'title' => $request->leave_type,
            'meta' => ($request->start_date?->format('d M Y') ?: '—').' · '.$request->days.' day(s)',
            'value' => strtoupper($request->status),
            'tone' => $request->status === 'approved' ? 'success' : ($request->status === 'rejected' ? 'danger' : 'warning'),
        ])->all();

        return ['items' => $items, 'metrics' => [['label' => 'Pending', 'value' => number_format($requests->where('status', 'pending')->count())]], 'href' => route('employees.leave.my')];
    }

    private function auditActivity(): array
    {
        $items = AuditLog::query()->with('user')->latest('id')->limit(8)->get()->map(fn (AuditLog $log): array => [
            'title' => $log->description ?: $log->event.' '.$log->module,
            'meta' => ($log->user?->displayName() ?: 'System').' · '.($log->created_at?->diffForHumans() ?: '—'),
            'value' => strtoupper($log->event),
            'tone' => 'neutral',
        ])->all();

        return ['items' => $items, 'href' => route('settings.audit-log')];
    }

    private function quickActions(User $user): array
    {
        $actions = [];

        if ($user->can('sales.customers.create')) {
            $actions[] = ['label' => 'New customer', 'href' => route('sales.customers.create'), 'icon' => 'users'];
        }
        if ($user->can('sales.orders.create')) {
            $actions[] = ['label' => 'New order', 'href' => route('sales.orders.create'), 'icon' => 'orders'];
        }
        if ($user->can('inventory.items.view')) {
            $actions[] = ['label' => 'Stock items', 'href' => route('inventory.items.index'), 'icon' => 'inventory'];
        }
        if ($user->can('employees.attendance.mark')) {
            $actions[] = ['label' => 'Mark attendance', 'href' => route('employees.attendance.index'), 'icon' => 'clock'];
        }
        if ($user->can('employees.my_leave.create')) {
            $actions[] = ['label' => 'Request leave', 'href' => route('employees.leave.my.create'), 'icon' => 'calendar'];
        }
        if ($user->can('pos.sale_screen.use')) {
            $actions[] = ['label' => 'Open POS', 'href' => route('pos.sale_screen.index'), 'icon' => 'pos'];
        }

        return ['items' => $actions];
    }

    private function money(float|int|string $value): string
    {
        return money($value, base_currency());
    }
}
