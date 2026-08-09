<?php

namespace App\Http\Controllers\Investment;

use App\Http\Controllers\Controller;
use App\Models\Investment;
use App\Support\ExportsCsv;
use App\Support\ExportsJson;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PortfolioController extends Controller
{
    use ExportsCsv;
    use ExportsJson;

    public function index(Request $request): View
    {
        $investments = Investment::query()
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        $types = Investment::query()->distinct()->orderBy('type')->pluck('type');

        $stats = [
            'count' => Investment::query()->count(),
            'cost' => round((float) Investment::query()->sum('total_cost'), 2),
            'value' => round((float) Investment::query()->get()->sum(fn ($i) => $i->marketValue()), 2),
        ];

        return view('investments.portfolio.index', compact('investments', 'types', 'stats'));
    }

    public function create(): View
    {
        return view('investments.portfolio.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

        $investment = Investment::create([
            'code' => next_document_number('investment', 'INV'),
            'name' => $data['name'],
            'type' => $data['type'],
            'institution' => $data['institution'] ?? null,
            'purchase_date' => $data['purchase_date'] ?? null,
            'quantity' => (string) $data['quantity'],
            'unit_cost' => (string) $data['unit_cost'],
            'total_cost' => (string) $data['total_cost'],
            'current_price' => $data['current_price'] ?? null,
            'current_value' => $data['current_value'] ?? null,
            'currency' => $data['currency'] ?? null,
            'maturity_date' => $data['maturity_date'] ?? null,
            'status' => $data['status'],
            'notes' => $data['notes'] ?? null,
        ]);

        return redirect()->route('investments.portfolio.index')
            ->with('toasts', [['type' => 'success', 'message' => "Investment {$investment->code} added to portfolio."]]);
    }

    public function edit(Investment $investment): View
    {
        return view('investments.portfolio.edit', compact('investment'));
    }

    public function update(Request $request, Investment $investment): RedirectResponse
    {
        $data = $this->validateData($request);

        $investment->update([
            'name' => $data['name'],
            'type' => $data['type'],
            'institution' => $data['institution'] ?? null,
            'purchase_date' => $data['purchase_date'] ?? null,
            'quantity' => (string) $data['quantity'],
            'unit_cost' => (string) $data['unit_cost'],
            'total_cost' => (string) $data['total_cost'],
            'current_price' => $data['current_price'] ?? null,
            'current_value' => $data['current_value'] ?? null,
            'currency' => $data['currency'] ?? null,
            'maturity_date' => $data['maturity_date'] ?? null,
            'status' => $data['status'],
            'notes' => $data['notes'] ?? null,
        ]);

        return back()->with('toasts', [['type' => 'success', 'message' => "Investment {$investment->code} updated."]]);
    }

    public function destroy(Investment $investment): RedirectResponse
    {
        $investment->delete();

        return redirect()->route('investments.portfolio.index')
            ->with('toasts', [['type' => 'success', 'message' => "Investment {$investment->code} removed from portfolio."]]);
    }

    public function export(Request $request): StreamedResponse
    {
        $investments = Investment::query()
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->orderBy('name')
            ->get();

        $rows = $investments->map(fn (Investment $i) => [
            'code' => $i->code,
            'name' => $i->name,
            'type' => ucfirst(str_replace('_', ' ', $i->type)),
            'institution' => $i->institution,
            'quantity' => $i->quantity,
            'total_cost' => $i->total_cost,
            'current_value' => $i->marketValue(),
            'gain_loss' => $i->gainLoss(),
            'status' => ucfirst($i->status),
        ]);

        $filename = 'investment-portfolio-'.now()->format('Y-m-d').'.'.($request->query('format') === 'json' ? 'json' : 'csv');

        return $request->query('format') === 'json'
            ? $this->streamJson($filename, $rows)
            : $this->streamCsv($filename, ['Code', 'Name', 'Type', 'Institution', 'Quantity', 'Cost', 'Market value', 'Gain/Loss', 'Status'], $rows->values());
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'type' => ['required', 'in:'.implode(',', Investment::typeOptions())],
            'institution' => ['nullable', 'string', 'max:190'],
            'purchase_date' => ['nullable', 'date'],
            'quantity' => ['required', 'numeric', 'min:0'],
            'unit_cost' => ['required', 'numeric', 'min:0'],
            'total_cost' => ['required', 'numeric', 'min:0'],
            'current_price' => ['nullable', 'numeric', 'min:0'],
            'current_value' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:8'],
            'maturity_date' => ['nullable', 'date'],
            'status' => ['required', 'in:active,matured,sold'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }
}