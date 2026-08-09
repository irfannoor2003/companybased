<?php

namespace App\Http\Controllers\Capital;

use App\Http\Controllers\Controller;
use App\Models\CapitalDrawing;
use App\Support\ExportsCsv;
use App\Support\ExportsJson;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DrawingController extends Controller
{
    use ExportsCsv;
    use ExportsJson;

    public function index(Request $request): View
    {
        $drawings = CapitalDrawing::query()
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->when($request->filled('recipient'), fn ($q) => $q->where('recipient', $request->recipient))
            ->when($request->filled('from'), fn ($q) => $q->where('drawing_date', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->where('drawing_date', '<=', $request->to))
            ->orderByDesc('drawing_date')
            ->paginate(20)
            ->withQueryString();

        $recipients = CapitalDrawing::query()->distinct()->orderBy('recipient')->pluck('recipient');

        $total = (float) CapitalDrawing::query()->sum('amount');

        return view('capital.drawings.index', compact('drawings', 'recipients', 'total'));
    }

    public function create(): View
    {
        return view('capital.drawings.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

        $drawing = CapitalDrawing::create([
            'reference' => next_document_number('capital_drawing', 'DRW'),
            'drawing_date' => $data['drawing_date'],
            'recipient' => $data['recipient'],
            'amount' => (string) $data['amount'],
            'currency' => $data['currency'],
            'method' => $data['method'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        return redirect()->route('capital.drawings.index')
            ->with('toasts', [['type' => 'success', 'message' => "Drawing {$drawing->reference} recorded."]]);
    }

    public function edit(CapitalDrawing $drawing): View
    {
        return view('capital.drawings.edit', compact('drawing'));
    }

    public function update(Request $request, CapitalDrawing $drawing): RedirectResponse
    {
        $data = $this->validateData($request);

        $drawing->update([
            'drawing_date' => $data['drawing_date'],
            'recipient' => $data['recipient'],
            'amount' => (string) $data['amount'],
            'currency' => $data['currency'],
            'method' => $data['method'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        return back()->with('toasts', [['type' => 'success', 'message' => "Drawing {$drawing->reference} updated."]]);
    }

    public function destroy(CapitalDrawing $drawing): RedirectResponse
    {
        $drawing->delete();

        return redirect()->route('capital.drawings.index')
            ->with('toasts', [['type' => 'success', 'message' => "Drawing {$drawing->reference} deleted."]]);
    }

    public function export(Request $request): StreamedResponse
    {
        $drawings = CapitalDrawing::query()
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->when($request->filled('recipient'), fn ($q) => $q->where('recipient', $request->recipient))
            ->when($request->filled('from'), fn ($q) => $q->where('drawing_date', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->where('drawing_date', '<=', $request->to))
            ->orderByDesc('drawing_date')
            ->get();

        $rows = $drawings->map(fn (CapitalDrawing $d) => [
            'reference' => $d->reference,
            'date' => $d->drawing_date->format('Y-m-d'),
            'recipient' => $d->recipient,
            'amount' => $d->amount,
            'currency' => $d->currency,
            'method' => $d->method ? ucfirst(str_replace('_', ' ', $d->method)) : null,
        ]);

        $filename = 'capital-drawings-'.now()->format('Y-m-d').'.'.($request->query('format') === 'json' ? 'json' : 'csv');

        return $request->query('format') === 'json'
            ? $this->streamJson($filename, $rows)
            : $this->streamCsv($filename, ['Reference', 'Date', 'Recipient', 'Amount', 'Currency', 'Method'], $rows->values());
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'drawing_date' => ['required', 'date'],
            'recipient' => ['required', 'string', 'max:190'],
            'amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'max:8'],
            'method' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }
}