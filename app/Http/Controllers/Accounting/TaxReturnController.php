<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\TaxReturn;
use App\Support\ExportsCsv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TaxReturnController extends Controller
{
    use ExportsCsv;

    public function index(Request $request): View
    {
        $returns = TaxReturn::query()
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('type'), fn ($q) => $q->where('tax_type', $request->type))
            ->orderByDesc('period_end')
            ->paginate(20)
            ->withQueryString();

        $totalDue = round((float) TaxReturn::query()->whereNotIn('status', ['paid'])->sum('tax_due'), 2);
        $totalPaid = round((float) TaxReturn::query()->where('status', 'paid')->sum('tax_due'), 2);

        return view('accounting.tax_returns.index', compact('returns', 'totalDue', 'totalPaid'));
    }

    public function create(): View
    {
        return view('accounting.tax_returns.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

        $tax = TaxReturn::create([
            'number' => next_document_number('tax_return', 'TR'),
            'tax_type' => $data['tax_type'],
            'period_label' => $data['period_label'],
            'period_start' => $data['period_start'],
            'period_end' => $data['period_end'],
            'gross_receipts' => $data['gross_receipts'],
            'taxable_amount' => $data['taxable_amount'],
            'tax_collected' => $data['tax_collected'],
            'tax_credits' => $data['tax_credits'],
            'tax_due' => $data['tax_due'],
            'status' => 'draft',
            'currency' => $data['currency'],
            'notes' => $data['notes'] ?? null,
        ]);

        return redirect()->route('accounting.tax_returns.show', $tax)
            ->with('toasts', [['type' => 'success', 'message' => "Tax return {$tax->number} created."]]);
    }

    public function show(TaxReturn $tax): View
    {
        return view('accounting.tax_returns.show', compact('tax'));
    }

    public function edit(TaxReturn $tax): View
    {
        return view('accounting.tax_returns.edit', compact('tax'));
    }

    public function update(Request $request, TaxReturn $tax): RedirectResponse
    {
        if ($tax->status === 'paid') {
            return back()->with('toasts', [['type' => 'danger', 'message' => 'Paid returns cannot be edited.']]);
        }

        $data = $this->validateData($request);

        $tax->update([
            'tax_type' => $data['tax_type'],
            'period_label' => $data['period_label'],
            'period_start' => $data['period_start'],
            'period_end' => $data['period_end'],
            'gross_receipts' => $data['gross_receipts'],
            'taxable_amount' => $data['taxable_amount'],
            'tax_collected' => $data['tax_collected'],
            'tax_credits' => $data['tax_credits'],
            'tax_due' => $data['tax_due'],
            'currency' => $data['currency'],
            'notes' => $data['notes'] ?? null,
        ]);

        return back()->with('toasts', [['type' => 'success', 'message' => "Tax return {$tax->number} updated."]]);
    }

    public function updateStatus(Request $request, TaxReturn $tax): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['filed', 'paid'])],
            'filed_at' => ['nullable', 'date'],
            'paid_at' => ['nullable', 'date'],
        ]);

        $updates = ['status' => $data['status']];

        if ($data['status'] === 'filed') {
            $updates['filed_at'] = $data['filed_at'] ?? now()->toDateString();
        }

        if ($data['status'] === 'paid') {
            if (! $tax->filed_at) {
                $updates['filed_at'] = now()->toDateString();
            }
            $updates['paid_at'] = $data['paid_at'] ?? now()->toDateString();
        }

        $tax->update($updates);

        return back()->with('toasts', [['type' => 'success', 'message' => "Tax return {$tax->number} marked {$data['status']}."]]);
    }

    public function destroy(TaxReturn $tax): RedirectResponse
    {
        $tax->delete();

        return redirect()->route('accounting.tax_returns.index')
            ->with('toasts', [['type' => 'success', 'message' => "Tax return {$tax->number} deleted."]]);
    }

    public function export(Request $request): StreamedResponse
    {
        $returns = TaxReturn::query()
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('type'), fn ($q) => $q->where('tax_type', $request->type))
            ->orderByDesc('period_end')
            ->get();

        return $this->streamCsv('tax-returns-'.now()->format('Y-m-d').'.csv', ['Number', 'Type', 'Period', 'Taxable', 'Collected', 'Credits', 'Due', 'Status'], $returns->map(fn (TaxReturn $t) => [
            $t->number,
            ucfirst($t->tax_type),
            $t->period_label,
            $t->taxable_amount,
            $t->tax_collected,
            $t->tax_credits,
            $t->tax_due,
            ucfirst($t->status),
        ]));
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'tax_type' => ['required', Rule::in(TaxReturn::typeOptions())],
            'period_label' => ['required', 'string', 'max:100'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'gross_receipts' => ['required', 'numeric', 'min:0'],
            'taxable_amount' => ['required', 'numeric', 'min:0'],
            'tax_collected' => ['required', 'numeric', 'min:0'],
            'tax_credits' => ['required', 'numeric', 'min:0'],
            'tax_due' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'max:8'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }
}