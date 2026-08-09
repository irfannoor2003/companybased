<?php

namespace App\Http\Controllers\Investment;

use App\Http\Controllers\Controller;
use App\Models\Investment;
use App\Support\ExportsCsv;
use App\Support\ExportsJson;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReturnController extends Controller
{
    use ExportsCsv;
    use ExportsJson;

    public function index(Request $request): View
    {
        $investments = Investment::query()
            ->withSum('dividends', 'amount')
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->orderBy('name')
            ->get();

        $types = Investment::query()->distinct()->orderBy('type')->pluck('type');

        $totals = $investments->reduce(function (array $carry, Investment $i) {
            $carry['cost'] += (float) $i->total_cost;
            $carry['value'] += $i->marketValue();
            $carry['gain_loss'] += $i->gainLoss();
            $carry['dividends'] += $i->totalDividends();

            return $carry;
        }, ['cost' => 0.0, 'value' => 0.0, 'gain_loss' => 0.0, 'dividends' => 0.0]);

        array_walk($totals, fn (&$v) => $v = round($v, 2));

        $totals['return_pct'] = $totals['cost'] > 0 ? round(($totals['gain_loss'] / $totals['cost']) * 100, 2) : 0.0;

        return view('investments.returns.index', compact('investments', 'types', 'totals'));
    }

    public function export(Request $request): StreamedResponse
    {
        $investments = Investment::query()
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->orderBy('name')
            ->get();

        $rows = collect([
            ['Code', 'Name', 'Cost', 'Market value', 'Gain/Loss', 'Return %', 'Dividends'],
        ]);

        foreach ($investments as $i) {
            $rows->push([
                $i->code, $i->name, number_format((float) $i->total_cost, 2),
                number_format($i->marketValue(), 2), number_format($i->gainLoss(), 2),
                number_format($i->returnPct(), 2) . '%', number_format($i->totalDividends(), 2),
            ]);
        }

        $filename = 'investment-returns-'.now()->format('Y-m-d').'.'.($request->query('format') === 'json' ? 'json' : 'csv');

        return $request->query('format') === 'json'
            ? $this->streamJson($filename, $investments->map(fn (Investment $i) => [
                'code' => $i->code, 'name' => $i->name, 'cost' => $i->total_cost,
                'value' => $i->marketValue(), 'gain_loss' => $i->gainLoss(),
                'return_pct' => $i->returnPct(), 'dividends' => $i->totalDividends(),
            ]))
            : $this->streamCsv($filename, ['Code', 'Name', 'Type', 'Market Value', 'Gain/Loss', 'Return %', 'Dividends'], $rows);
    }
}