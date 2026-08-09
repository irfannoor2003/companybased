<?php

namespace App\Http\Controllers\Capital;

use App\Http\Controllers\Controller;
use App\Models\CapitalContribution;
use App\Services\CapitalService;
use App\Support\ExportsCsv;
use App\Support\ExportsJson;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EquityController extends Controller
{
    use ExportsCsv;
    use ExportsJson;

    public function __construct(private readonly CapitalService $capital) {}

    public function index(Request $request): View
    {
        $from = $request->filled('from') ? $request->from : null;
        $to = $request->filled('to') ? $request->to : null;

        $totals = $this->capital->totals($from, $to);

        $byOwner = CapitalContribution::query()
            ->selectRaw('contributor as owner, sum(amount) as total')
            ->when($from, fn ($q) => $q->where('contribution_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('contribution_date', '<=', $to))
            ->groupBy('contributor')
            ->orderBy('contributor')
            ->get();

        $byRecipient = \App\Models\CapitalDrawing::query()
            ->selectRaw('recipient as owner, sum(amount) as total')
            ->when($from, fn ($q) => $q->where('drawing_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('drawing_date', '<=', $to))
            ->groupBy('recipient')
            ->orderBy('recipient')
            ->get();

        $parties = $byOwner->mapWithKeys(fn ($row) => [$row->owner => 0.0])
            ->union($byRecipient->mapWithKeys(fn ($row) => [$row->owner => 0.0]))
            ->map(function ($drawings, $owner) use ($byOwner, $byRecipient) {
                $contributed = (float) ($byOwner->firstWhere('owner', $owner)?->total ?? 0);
                $drawn = (float) ($byRecipient->firstWhere('owner', $owner)?->total ?? 0);

                return [
                    'owner' => $owner,
                    'contributions' => $contributed,
                    'drawings' => $drawn,
                    'equity' => round($contributed - $drawn, 2),
                ];
            })
            ->sortByDesc('equity')
            ->values();

        return view('capital.equity.index', compact('totals', 'parties', 'from', 'to'));
    }

    public function export(Request $request): StreamedResponse
    {
        $from = $request->filled('from') ? $request->from : null;
        $to = $request->filled('to') ? $request->to : null;

        $totals = $this->capital->totals($from, $to);

        $rows = collect([
            ['Owner', 'Contributions', 'Drawings', 'Equity'],
            ['TOTAL', number_format($totals['contributions'], 2), number_format($totals['drawings'], 2), number_format($totals['equity'], 2)],
        ]);

        $filename = 'capital-equity-'.now()->format('Y-m-d').'.'.($request->query('format') === 'json' ? 'json' : 'csv');

        return $request->query('format') === 'json'
            ? $this->streamJson($filename, ['period' => ['from' => $from, 'to' => $to], 'totals' => $totals])
            : $this->streamCsv($filename, ['Owner', 'Contributions', 'Drawings', 'Equity'], $rows);
    }
}