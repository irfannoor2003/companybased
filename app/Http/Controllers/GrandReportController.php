<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\BankTransaction;
use App\Models\CapitalContribution;
use App\Models\Employee;
use App\Models\FixedAsset;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Investment;
use App\Models\JournalEntry;
use App\Models\LeaveRequest;
use App\Models\PayrollRun;
use App\Models\PosSale;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\SalesCustomer;
use App\Models\SalesInvoice;
use App\Models\Visit;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GrandReportController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorizePermission('reports.grand.view');

        $period = in_array($request->string('period')->toString(), ['daily', 'weekly', 'monthly', 'yearly'], true)
            ? $request->string('period')->toString()
            : 'monthly';
        $anchor = $request->filled('date') ? Carbon::parse($request->date) : now();
        [$from, $to] = $this->periodRange($period, $anchor);

        $salesInvoices = SalesInvoice::query()->whereBetween('issue_date', [$from, $to]);
        $purchaseInvoices = PurchaseInvoice::query()->whereBetween('issue_date', [$from, $to]);
        $bankIn = BankTransaction::query()->whereBetween('transaction_date', [$from, $to])->whereIn('type', ['deposit', 'transfer_in']);
        $bankOut = BankTransaction::query()->whereBetween('transaction_date', [$from, $to])->whereIn('type', ['withdrawal', 'transfer_out']);
        $attendance = AttendanceRecord::query()->whereBetween('attendance_date', [$from->toDateString(), $to->toDateString()]);
        $leave = LeaveRequest::query()->whereBetween('start_date', [$from->toDateString(), $to->toDateString()]);
        $pos = PosSale::query()->where('status', 'completed')->whereBetween('sold_at', [$from, $to]);

        $lowStock = InventoryItem::query()->active()->withSum('stock as on_hand', 'quantity')->get()
            ->filter(fn (InventoryItem $item): bool => (float) $item->reorder_level > 0 && (float) $item->on_hand <= (float) $item->reorder_level)
            ->count();

        $summary = [
            'sales_revenue' => (float) (clone $salesInvoices)->sum('total'),
            'sales_collected' => (float) (clone $salesInvoices)->sum('paid_amount'),
            'sales_orders' => (clone $salesInvoices)->count(),
            'customers' => SalesCustomer::query()->where('is_active', true)->count(),
            'purchase_value' => (float) (clone $purchaseInvoices)->sum('total'),
            'purchase_orders' => PurchaseOrder::query()->whereBetween('order_date', [$from->toDateString(), $to->toDateString()])->count(),
            'cash_in' => (float) (clone $bankIn)->sum('amount'),
            'cash_out' => (float) (clone $bankOut)->sum('amount'),
            'posted_journals' => JournalEntry::query()->where('status', 'posted')->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()])->count(),
            'attendance_records' => (clone $attendance)->count(),
            'leave_requests' => (clone $leave)->count(),
            'pos_revenue' => (float) (clone $pos)->sum('total'),
            'stock_movements' => InventoryMovement::query()->whereBetween('created_at', [$from, $to])->count(),
            'low_stock' => $lowStock,
            'assets_added' => FixedAsset::query()->whereBetween('purchase_date', [$from->toDateString(), $to->toDateString()])->count(),
            'investments_added' => Investment::query()->whereBetween('purchase_date', [$from->toDateString(), $to->toDateString()])->count(),
            'capital_added' => (float) CapitalContribution::query()->whereBetween('contribution_date', [$from->toDateString(), $to->toDateString()])->sum('amount'),
            'visits' => Visit::query()->whereBetween('scheduled_at', [$from->toDateString(), $to->toDateString()])->count(),
            'employees' => Employee::query()->active()->count(),
            'payroll_runs' => PayrollRun::query()->whereBetween('period_end', [$from->toDateString(), $to->toDateString()])->count(),
        ];

        $chart = $this->chartData($period, $anchor);
        $activity = [
            ['label' => 'Sales', 'value' => $summary['sales_revenue'], 'tone' => 'primary'],
            ['label' => 'Purchasing', 'value' => $summary['purchase_value'], 'tone' => 'info'],
            ['label' => 'POS', 'value' => $summary['pos_revenue'], 'tone' => 'success'],
            ['label' => 'Cash in', 'value' => $summary['cash_in'], 'tone' => 'success'],
            ['label' => 'Cash out', 'value' => $summary['cash_out'], 'tone' => 'warning'],
        ];

        return view('reports.grand', compact('period', 'anchor', 'from', 'to', 'summary', 'chart', 'activity', 'lowStock'));
    }

    private function periodRange(string $period, Carbon $anchor): array
    {
        return match ($period) {
            'daily' => [$anchor->copy()->startOfDay(), $anchor->copy()->endOfDay()],
            'weekly' => [$anchor->copy()->startOfWeek(Carbon::MONDAY), $anchor->copy()->endOfWeek(Carbon::SUNDAY)],
            'yearly' => [$anchor->copy()->startOfYear(), $anchor->copy()->endOfYear()],
            default => [$anchor->copy()->startOfMonth(), $anchor->copy()->endOfMonth()],
        };
    }

    private function chartData(string $period, Carbon $anchor): array
    {
        $count = match ($period) {
            'daily' => 7,
            'weekly' => 8,
            'yearly' => 5,
            default => 6,
        };
        $labels = [];
        $revenue = [];
        $cashIn = [];
        $cashOut = [];

        for ($index = $count - 1; $index >= 0; $index--) {
            $start = match ($period) {
                'daily' => $anchor->copy()->subDays($index)->startOfDay(),
                'weekly' => $anchor->copy()->subWeeks($index)->startOfWeek(Carbon::MONDAY),
                'yearly' => $anchor->copy()->subYears($index)->startOfYear(),
                default => $anchor->copy()->subMonthsNoOverflow($index)->startOfMonth(),
            };
            $end = match ($period) {
                'daily' => $start->copy()->endOfDay(),
                'weekly' => $start->copy()->endOfWeek(Carbon::SUNDAY),
                'yearly' => $start->copy()->endOfYear(),
                default => $start->copy()->endOfMonth(),
            };
            $labels[] = match ($period) {
                'daily' => $start->format('d M'),
                'weekly' => 'W'.$start->format('W'),
                'yearly' => $start->format('Y'),
                default => $start->format('M Y'),
            };
            $revenue[] = round((float) SalesInvoice::query()->whereBetween('issue_date', [$start, $end])->sum('total'), 2);
            $cashIn[] = round((float) BankTransaction::query()->whereBetween('transaction_date', [$start, $end])->whereIn('type', ['deposit', 'transfer_in'])->sum('amount'), 2);
            $cashOut[] = round((float) BankTransaction::query()->whereBetween('transaction_date', [$start, $end])->whereIn('type', ['withdrawal', 'transfer_out'])->sum('amount'), 2);
        }

        return [
            'labels' => $labels,
            'revenue' => $revenue,
            'cashIn' => $cashIn,
            'cashOut' => $cashOut,
        ];
    }
}
