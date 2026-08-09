<?php

namespace App\Http\Controllers\Investment;

use App\Http\Controllers\Controller;
use App\Models\Investment;
use App\Models\InvestmentDividend;
use App\Support\ExportsCsv;
use App\Support\ExportsJson;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DividendController extends Controller
{
    use ExportsCsv;
    use ExportsJson;

    public function index(Request $request): View
    {
        $dividends = InvestmentDividend::query()
            ->with('investment')
            ->when($request->filled('search'), function ($q) use ($request) {
                $q->whereHas('investment', fn ($inv) => $inv->where('name', 'like', "%{$request->search}%")->orWhere('code', 'like', "%{$request->search}%"));
            })
            ->when($request->filled('investment_id'), fn ($q) => $q->where('investment_id', $request->investment_id))
            ->when($request->filled('from'), fn ($q) => $q->where('dividend_date', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->where('dividend_date', '<=', $request->to))
            ->orderByDesc('dividend_date')
            ->paginate(20)
            ->withQueryString();

        $investments = Investment::query()->whereHas('dividends')->orWhere('status', '!=', 'sold')->orderBy('name')->get();
        $total = round((float) InvestmentDividend::query()->sum('amount'), 2);

        return view('investments.dividends.index', compact('dividends', 'investments', 'total'));
    }

    public function create(): View
    {
        $investments = Investment::query()->where('status', 'active')->orderBy('name')->get();

        return view('investments.dividends.create', compact('investments'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

        InvestmentDividend::create([
            'investment_id' => $data['investment_id'],
            'dividend_date' => $data['dividend_date'],
            'amount' => (string) $data['amount'],
            'currency' => $data['currency'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        return redirect()->route('investments.dividends.index')
            ->with('toasts', [['type' => 'success', 'message' => 'Dividend recorded.']]);
    }

    public function edit(InvestmentDividend $dividend): View
    {
        $investments = Investment::query()->orderBy('name')->get();

        return view('investments.dividends.edit', compact('dividend', 'investments'));
    }

    public function update(Request $request, InvestmentDividend $dividend): RedirectResponse
    {
        $data = $this->validateData($request);

        $dividend->update([
            'investment_id' => $data['investment_id'],
            'dividend_date' => $data['dividend_date'],
            'amount' => (string) $data['amount'],
            'currency' => $data['currency'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        return back()->with('toasts', [['type' => 'success', 'message' => 'Dividend updated.']]);
    }

    public function destroy(InvestmentDividend $dividend): RedirectResponse
    {
        $dividend->delete();

        return redirect()->route('investments.dividends.index')
            ->with('toasts', [['type' => 'success', 'message' => 'Dividend deleted.']]);
    }

    public function export(Request $request): StreamedResponse
    {
        $dividends = InvestmentDividend::query()
            ->with('investment')
            ->when($request->filled('search'), function ($q) use ($request) {
                $q->whereHas('investment', fn ($inv) => $inv->where('name', 'like', "%{$request->search}%")->orWhere('code', 'like', "%{$request->search}%"));
            })
            ->when($request->filled('investment_id'), fn ($q) => $q->where('investment_id', $request->investment_id))
            ->when($request->filled('from'), fn ($q) => $q->where('dividend_date', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->where('dividend_date', '<=', $request->to))
            ->orderByDesc('dividend_date')
            ->get();

        $rows = $dividends->map(fn (InvestmentDividend $d) => [
            'date' => $d->dividend_date?->format('Y-m-d'),
            'investment_code' => $d->investment?->code,
            'investment_name' => $d->investment?->name,
            'amount' => $d->amount,
            'currency' => $d->currency,
        ]);

        $filename = 'investment-dividends-'.now()->format('Y-m-d').'.'.($request->query('format') === 'json' ? 'json' : 'csv');

        return $request->query('format') === 'json'
            ? $this->streamJson($filename, $rows)
            : $this->streamCsv($filename, ['Date', 'Code', 'Investment', 'Amount', 'Currency'], $rows->values());
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'investment_id' => ['required', 'integer', 'exists:investments,id'],
            'dividend_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['nullable', 'string', 'max:8'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }
}