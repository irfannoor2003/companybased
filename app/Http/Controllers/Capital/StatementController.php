<?php

namespace App\Http\Controllers\Capital;

use App\Http\Controllers\Controller;
use App\Services\CapitalService;
use App\Support\ExportsCsv;
use App\Support\ExportsJson;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StatementController extends Controller
{
    use ExportsCsv;
    use ExportsJson;

    public function __construct(private readonly CapitalService $capital) {}

    public function index(Request $request): View
    {
        $parties = $this->capital->contributors();

        $party = $request->filled('party') ? $request->party : null;
        $from = $request->filled('from') ? $request->from : null;
        $to = $request->filled('to') ? $request->to : null;

        $rows = $this->capital->statement($party, $from, $to);

        $totals = $this->capital->totals($from, $to);

        return view('capital.statements.index', compact('parties', 'party', 'from', 'to', 'rows', 'totals'));
    }

    public function export(Request $request): StreamedResponse
    {
        $party = $request->filled('party') ? $request->party : null;
        $from = $request->filled('from') ? $request->from : null;
        $to = $request->filled('to') ? $request->to : null;

        $rows = $this->capital->statement($party, $from, $to)->map(fn ($row) => [
            $row['date']->format('Y-m-d'),
            $row['type'] === 'contribution' ? 'Contribution' : 'Drawing',
            $row['reference'],
            $row['party'],
            $row['type'] === 'contribution' ? number_format($row['amount'], 2) : number_format(abs($row['amount']), 2),
            number_format($row['balance'], 2),
        ]);

        $filename = 'capital-statement-'.now()->format('Y-m-d').'.'.($request->query('format') === 'json' ? 'json' : 'csv');

        return $request->query('format') === 'json'
            ? $this->streamJson($filename, $rows->map(fn ($row) => [
                'date' => $row[0],
                'type' => $row[1],
                'reference' => $row[2],
                'party' => $row[3],
                'amount' => $row[4],
                'balance' => $row[5],
            ]))
            : $this->streamCsv($filename, ['Date', 'Type', 'Reference', 'Party', 'Amount', 'Balance'], $rows);
    }
}