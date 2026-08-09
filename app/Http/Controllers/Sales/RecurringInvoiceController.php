<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\SalesCustomer;
use App\Models\SalesRecurringInvoice;
use App\Support\DocumentItems;
use App\Support\ExportsCsv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RecurringInvoiceController extends Controller
{
    use ExportsCsv;

    public function index(Request $request): View
    {
        $recurringInvoices = SalesRecurringInvoice::query()
            ->with(['customer'])
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', "%{$request->search}%"))
            ->when($request->filled('customer'), fn ($q) => $q->where('customer_id', $request->customer))
            ->when($request->filled('status'), fn ($q) => $q->when($request->status === 'active', fn ($q) => $q->where('is_active', true))
                ->when($request->status === 'inactive', fn ($q) => $q->where('is_active', false)))
            ->latest('next_run_date')
            ->paginate(20)
            ->withQueryString();

        $customers = SalesCustomer::query()->orderBy('company_name')->get();

        return view('sales.recurring_invoices.index', compact('recurringInvoices', 'customers'));
    }

    public function create(): View
    {
        $customers = SalesCustomer::query()->orderBy('company_name')->get();
        $products = Product::query()->where('is_active', true)->orderBy('name')->get();

        return view('sales.recurring_invoices.create', compact('customers', 'products'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

        $recurring = SalesRecurringInvoice::create([
            'customer_id' => $data['customer_id'],
            'name' => $data['name'],
            'frequency' => $data['frequency'],
            'next_run_date' => $data['next_run_date'],
            'day_of_cycle' => $data['day_of_cycle'] ?? 1,
            'currency' => $data['currency'] ?? null,
            'is_active' => $request->boolean('is_active', true),
            'notes' => $data['notes'] ?? null,
        ]);

        $totals = DocumentItems::sync($recurring, $request->input('items', []));
        $recurring->update(['subtotal' => $totals['subtotal'], 'tax_amount' => $totals['tax'], 'total' => $totals['total']]);

        return redirect()->route('sales.recurring_invoices.edit', $recurring)
            ->with('toasts', [['type' => 'success', 'message' => "Recurring invoice \"{$recurring->name}\" created."]]);
    }

    public function edit(SalesRecurringInvoice $recurringInvoice): View
    {
        $recurringInvoice->load(['customer', 'items.product']);
        $customers = SalesCustomer::query()->orderBy('company_name')->get();
        $products = Product::query()->where('is_active', true)->orderBy('name')->get();

        return view('sales.recurring_invoices.edit', compact('recurringInvoice', 'customers', 'products'));
    }

    public function update(Request $request, SalesRecurringInvoice $recurringInvoice): RedirectResponse
    {
        $data = $this->validateData($request);

        $recurringInvoice->update([
            'customer_id' => $data['customer_id'],
            'name' => $data['name'],
            'frequency' => $data['frequency'],
            'next_run_date' => $data['next_run_date'],
            'day_of_cycle' => $data['day_of_cycle'] ?? 1,
            'currency' => $data['currency'] ?? null,
            'is_active' => $request->boolean('is_active', true),
            'notes' => $data['notes'] ?? null,
        ]);

        $totals = DocumentItems::sync($recurringInvoice, $request->input('items', []));
        $recurringInvoice->update(['subtotal' => $totals['subtotal'], 'tax_amount' => $totals['tax'], 'total' => $totals['total']]);

        return back()->with('toasts', [['type' => 'success', 'message' => "Recurring invoice \"{$recurringInvoice->name}\" updated."]]);
    }

    public function destroy(SalesRecurringInvoice $recurringInvoice): RedirectResponse
    {
        $name = $recurringInvoice->name;
        $recurringInvoice->delete();

        return redirect()->route('sales.recurring_invoices.index')
            ->with('toasts', [['type' => 'success', 'message' => "Recurring invoice \"{$name}\" deleted."]]);
    }

    public function export(Request $request): StreamedResponse
    {
        $recurringInvoices = SalesRecurringInvoice::query()
            ->with(['customer'])
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', "%{$request->search}%"))
            ->when($request->filled('customer'), fn ($q) => $q->where('customer_id', $request->customer))
            ->latest('next_run_date')
            ->get();

        return $this->streamCsv('recurring-invoices-'.now()->format('Y-m-d').'.csv', ['Name', 'Customer', 'Frequency', 'Next run', 'Last run', 'Total', 'Status'], $recurringInvoices->map(fn (SalesRecurringInvoice $r) => [
            $r->name,
            $r->customer?->company_name,
            ucfirst($r->frequency),
            $r->next_run_date?->format('Y-m-d'),
            $r->last_run_date?->format('Y-m-d') ?? '—',
            $r->total,
            $r->is_active ? 'Active' : 'Inactive',
        ]));
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'customer_id' => ['required', 'integer', Rule::exists('sales_customers', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'frequency' => ['required', Rule::in(SalesRecurringInvoice::frequencyOptions())],
            'next_run_date' => ['required', 'date'],
            'day_of_cycle' => ['nullable', 'integer', 'min:1', 'max:31'],
            'currency' => ['nullable', 'string', 'max:8'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1'],
        ]);
    }
}
