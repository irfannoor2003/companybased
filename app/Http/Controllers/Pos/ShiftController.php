<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\PosShift;
use App\Support\ExportsCsv;
use App\Support\ExportsJson;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ShiftController extends Controller
{
    use ExportsCsv;
    use ExportsJson;

    public function index(Request $request): View
    {
        $shifts = PosShift::query()
            ->with(['opener', 'closer'])
            ->withCount('sales')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('opened_at', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('opened_at', '<=', $request->to))
            ->orderByDesc('opened_at')
            ->paginate(20)
            ->withQueryString();

        $openShift = PosShift::query()->where('status', 'open')->latest('opened_at')->first();

        return view('pos.shifts.index', compact('shifts', 'openShift'));
    }

    public function open(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'opening_cash' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        if (PosShift::query()->where('status', 'open')->exists()) {
            return back()->with('toasts', [['type' => 'error', 'message' => 'A shift is already open. Close it first.']]);
        }

        $shift = PosShift::create([
            'shift_number' => next_document_number('pos_shift', 'SHF'),
            'opened_by' => auth()->id(),
            'opened_at' => now(),
            'opening_cash' => (string) $data['opening_cash'],
            'status' => 'open',
            'notes' => $data['notes'] ?? null,
        ]);

        return redirect()->route('pos.shifts.index')
            ->with('toasts', [['type' => 'success', 'message' => "Shift {$shift->shift_number} opened."]]);
    }

    public function close(Request $request, PosShift $shift): RedirectResponse
    {
        if (! $shift->isOpen()) {
            return back()->with('toasts', [['type' => 'error', 'message' => 'This shift is already closed.']]);
        }

        $data = $request->validate([
            'counted_cash' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $expected = round((float) $shift->opening_cash + $shift->salesTotal(), 2);
        $counted = round((float) $data['counted_cash'], 2);

        $shift->update([
            'status' => 'closed',
            'closed_by' => auth()->id(),
            'closed_at' => now(),
            'expected_cash' => (string) $expected,
            'counted_cash' => (string) $counted,
            'variance' => (string) round($counted - $expected, 2),
            'notes' => $data['notes'] ?? $shift->notes,
        ]);

        return redirect()->route('pos.shifts.index')
            ->with('toasts', [['type' => 'success', 'message' => "Shift {$shift->shift_number} closed."]]);
    }

    public function export(Request $request): StreamedResponse
    {
        $shifts = PosShift::query()
            ->with(['opener', 'closer'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->orderByDesc('opened_at')
            ->get();

        $rows = $shifts->map(fn (PosShift $s) => [
            'shift_number' => $s->shift_number,
            'opened_by' => $s->opener?->name,
            'opened_at' => $s->opened_at?->format('Y-m-d H:i'),
            'opening_cash' => $s->opening_cash,
            'sales_total' => $s->salesTotal(),
            'expected_cash' => $s->expected_cash,
            'counted_cash' => $s->counted_cash,
            'variance' => $s->variance,
            'status' => ucfirst($s->status),
        ]);

        $filename = 'pos-shifts-'.now()->format('Y-m-d').'.'.($request->query('format') === 'json' ? 'json' : 'csv');

        return $request->query('format') === 'json'
            ? $this->streamJson($filename, $rows)
            : $this->streamCsv($filename, ['Shift', 'Opened by', 'Opened at', 'Opening cash', 'Sales', 'Expected', 'Counted', 'Variance', 'Status'], $rows->values());
    }
}