<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\PosReconciliation;
use App\Models\PosShift;
use App\Support\ExportsCsv;
use App\Support\ExportsJson;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReconciliationController extends Controller
{
    use ExportsCsv;
    use ExportsJson;

    public function index(Request $request): View
    {
        $reconciliations = PosReconciliation::query()
            ->with(['shift', 'reconciler'])
            ->when($request->filled('from'), fn ($q) => $q->whereDate('reconciled_at', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('reconciled_at', '<=', $request->to))
            ->orderByDesc('reconciled_at')
            ->paginate(20)
            ->withQueryString();

        return view('pos.reconciliations.index', compact('reconciliations'));
    }

    public function create(): View
    {
        $shifts = PosShift::query()->where('status', 'closed')->orderByDesc('opened_at')->get();

        return view('pos.reconciliations.create', compact('shifts'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'shift_id' => ['required', 'integer', 'exists:pos_shifts,id'],
            'opening_cash' => ['required', 'numeric', 'min:0'],
            'counted_cash' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $shift = PosShift::query()->findOrFail($data['shift_id']);

        $salesTotal = $shift->salesTotal();
        $expected = round((float) $data['opening_cash'] + $salesTotal, 2);
        $counted = round((float) $data['counted_cash'], 2);

        PosReconciliation::create([
            'shift_id' => $shift->id,
            'reconciled_by' => auth()->id(),
            'reconciled_at' => now(),
            'opening_cash' => (string) $data['opening_cash'],
            'sales_total' => (string) $salesTotal,
            'expected_cash' => (string) $expected,
            'counted_cash' => (string) $counted,
            'variance' => (string) round($counted - $expected, 2),
            'notes' => $data['notes'] ?? null,
        ]);

        return redirect()->route('pos.reconciliations.index')
            ->with('toasts', [['type' => 'success', 'message' => 'Till reconciliation recorded.']]);
    }

    public function export(Request $request): StreamedResponse
    {
        $reconciliations = PosReconciliation::query()
            ->with(['shift', 'reconciler'])
            ->when($request->filled('from'), fn ($q) => $q->whereDate('reconciled_at', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('reconciled_at', '<=', $request->to))
            ->orderByDesc('reconciled_at')
            ->get();

        $rows = $reconciliations->map(fn (PosReconciliation $r) => [
            'shift' => $r->shift?->shift_number,
            'reconciled_at' => $r->reconciled_at?->format('Y-m-d H:i'),
            'reconciled_by' => $r->reconciler?->name,
            'opening_cash' => $r->opening_cash,
            'sales_total' => $r->sales_total,
            'expected_cash' => $r->expected_cash,
            'counted_cash' => $r->counted_cash,
            'variance' => $r->variance,
        ]);

        $filename = 'pos-reconciliations-'.now()->format('Y-m-d').'.'.($request->query('format') === 'json' ? 'json' : 'csv');

        return $request->query('format') === 'json'
            ? $this->streamJson($filename, $rows)
            : $this->streamCsv($filename, ['Shift', 'Reconciled at', 'By', 'Opening', 'Sales', 'Expected', 'Counted', 'Variance'], $rows->values());
    }
}