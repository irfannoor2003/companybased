<?php

namespace App\Http\Controllers\Capital;

use App\Http\Controllers\Controller;
use App\Models\CapitalContribution;
use App\Support\ExportsCsv;
use App\Support\ExportsJson;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContributionController extends Controller
{
    use ExportsCsv;
    use ExportsJson;

    public function index(Request $request): View
    {
        $contributions = CapitalContribution::query()
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->when($request->filled('contributor'), fn ($q) => $q->where('contributor', $request->contributor))
            ->when($request->filled('from'), fn ($q) => $q->where('contribution_date', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->where('contribution_date', '<=', $request->to))
            ->orderByDesc('contribution_date')
            ->paginate(20)
            ->withQueryString();

        $contributors = CapitalContribution::query()->distinct()->orderBy('contributor')->pluck('contributor');

        $total = (float) CapitalContribution::query()->sum('amount');

        return view('capital.contributions.index', compact('contributions', 'contributors', 'total'));
    }

    public function create(): View
    {
        return view('capital.contributions.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

        $contribution = CapitalContribution::create([
            'reference' => next_document_number('capital_contribution', 'CAP'),
            'contribution_date' => $data['contribution_date'],
            'contributor' => $data['contributor'],
            'amount' => (string) $data['amount'],
            'currency' => $data['currency'],
            'method' => $data['method'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        return redirect()->route('capital.contributions.index')
            ->with('toasts', [['type' => 'success', 'message' => "Contribution {$contribution->reference} recorded."]]);
    }

    public function edit(CapitalContribution $contribution): View
    {
        return view('capital.contributions.edit', compact('contribution'));
    }

    public function update(Request $request, CapitalContribution $contribution): RedirectResponse
    {
        $data = $this->validateData($request);

        $contribution->update([
            'contribution_date' => $data['contribution_date'],
            'contributor' => $data['contributor'],
            'amount' => (string) $data['amount'],
            'currency' => $data['currency'],
            'method' => $data['method'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        return back()->with('toasts', [['type' => 'success', 'message' => "Contribution {$contribution->reference} updated."]]);
    }

    public function destroy(CapitalContribution $contribution): RedirectResponse
    {
        $contribution->delete();

        return redirect()->route('capital.contributions.index')
            ->with('toasts', [['type' => 'success', 'message' => "Contribution {$contribution->reference} deleted."]]);
    }

    public function export(Request $request): StreamedResponse
    {
        $contributions = CapitalContribution::query()
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->when($request->filled('contributor'), fn ($q) => $q->where('contributor', $request->contributor))
            ->when($request->filled('from'), fn ($q) => $q->where('contribution_date', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->where('contribution_date', '<=', $request->to))
            ->orderByDesc('contribution_date')
            ->get();

        $rows = $contributions->map(fn (CapitalContribution $c) => [
            'reference' => $c->reference,
            'date' => $c->contribution_date->format('Y-m-d'),
            'contributor' => $c->contributor,
            'amount' => $c->amount,
            'currency' => $c->currency,
            'method' => $c->method ? ucfirst(str_replace('_', ' ', $c->method)) : null,
        ]);

        $filename = 'capital-contributions-'.now()->format('Y-m-d').'.'.($request->query('format') === 'json' ? 'json' : 'csv');

        return $request->query('format') === 'json'
            ? $this->streamJson($filename, $rows)
            : $this->streamCsv($filename, ['Reference', 'Date', 'Contributor', 'Amount', 'Currency', 'Method'], $rows->values());
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'contribution_date' => ['required', 'date'],
            'contributor' => ['required', 'string', 'max:190'],
            'amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'max:8'],
            'method' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }
}