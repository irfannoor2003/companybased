<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\SalesOrder;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SalesReportController extends Controller
{
    public function index(Request $request): View
    {
        $salesmen = User::whereHas('roles', fn ($q) => $q->where('name', 'Salesman'))->orderBy('name')->get();

        $query = SalesOrder::query()
            ->with('salesman')
            ->whereIn('status', ['confirmed', 'packed', 'shipped', 'delivered']);

        if ($request->filled('salesman_id')) {
            $query->where('salesman_id', $request->salesman_id);
        }

        if ($request->filled('from')) {
            $query->where('issue_date', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->where('issue_date', '<=', $request->to);
        }

        $orders = $query->orderByDesc('issue_date')->paginate(30)->withQueryString();

        $summary = SalesOrder::query()
            ->whereIn('status', ['confirmed', 'packed', 'shipped', 'delivered'])
            ->when($request->filled('salesman_id'), fn ($q) => $q->where('salesman_id', $request->salesman_id))
            ->when($request->filled('from'), fn ($q) => $q->where('issue_date', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->where('issue_date', '<=', $request->to))
            ->selectRaw('salesman_id, COUNT(*) as order_count, SUM(total) as total_value')
            ->groupBy('salesman_id')
            ->with('salesman')
            ->get();

        return view('sales.reports.salesman', compact('orders', 'summary', 'salesmen'));
    }

    public function export(Request $request)
    {
        $query = SalesOrder::query()
            ->with('salesman', 'customer')
            ->whereIn('status', ['confirmed', 'packed', 'shipped', 'delivered']);

        if ($request->filled('salesman_id')) {
            $query->where('salesman_id', $request->salesman_id);
        }

        if ($request->filled('from')) {
            $query->where('issue_date', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->where('issue_date', '<=', $request->to);
        }

        $orders = $query->orderByDesc('issue_date')->get();

        $headers = ['Order #', 'Date', 'Salesman', 'Customer', 'Status', 'Total'];

        $rows = $orders->map(fn ($o) => [
            $o->number,
            $o->issue_date,
            $o->salesman?->name ?? '—',
            $o->customer?->company_name ?? '—',
            $o->status,
            $o->total,
        ]);

        $csv = implode("\n", array_map(fn ($r) => implode(',', $r), array_merge([$headers], $rows->toArray())));

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="sales-by-salesman-'.now()->format('Y-m-d').'.csv"',
        ]);
    }
}
