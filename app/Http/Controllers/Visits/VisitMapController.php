<?php

namespace App\Http\Controllers\Visits;

use App\Http\Controllers\Controller;
use App\Models\Visit;
use App\Support\ExportsCsv;
use App\Support\ExportsJson;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VisitMapController extends Controller
{
    use ExportsCsv;
    use ExportsJson;

    public function index(Request $request): View
    {
        $from = $request->filled('from') ? $request->from : now()->startOfMonth()->toDateString();
        $to = $request->filled('to') ? $request->to : now()->endOfMonth()->toDateString();

        $visits = Visit::query()
            ->with(['customer', 'salesRep', 'pitStops'])
            ->whereBetween('scheduled_at', [$from, $to])
            ->orderByDesc('scheduled_at')
            ->get();

        $markers = $this->markers($visits);

        return view('visits.map', compact('visits', 'markers', 'from', 'to'));
    }

    public function export(Request $request): StreamedResponse
    {
        $from = $request->filled('from') ? $request->from : now()->startOfMonth()->toDateString();
        $to = $request->filled('to') ? $request->to : now()->endOfMonth()->toDateString();

        $visits = Visit::query()
            ->with(['customer', 'salesRep', 'pitStops'])
            ->whereBetween('scheduled_at', [$from, $to])
            ->get();

        $rows = $this->markers($visits)->map(fn ($m) => [
            'visit' => $m['visit'],
            'customer' => $m['customer'],
            'type' => $m['type'],
            'lat' => $m['lat'],
            'lng' => $m['lng'],
        ]);

        $filename = "visit-map-{$from}-to-{$to}.".($request->query('format') === 'json' ? 'json' : 'csv');

        return $request->query('format') === 'json'
            ? $this->streamJson($filename, $rows)
            : $this->streamCsv($filename, ['Visit', 'Customer', 'Type', 'Lat', 'Lng'], $rows->values());
    }

    private function markers(Collection $visits): Collection
    {
        return $visits->flatMap(function (Visit $visit) {
            $points = collect();

            if ($visit->start_lat !== null && $visit->start_lng !== null) {
                $points->push([
                    'visit' => $visit->visit_number,
                    'customer' => $visit->customer?->company_name ?? '—',
                    'type' => 'start',
                    'lat' => (float) $visit->start_lat,
                    'lng' => (float) $visit->start_lng,
                ]);
            }

            foreach ($visit->pitStops as $stop) {
                if ($stop->lat === null || $stop->lng === null) {
                    continue;
                }

                $points->push([
                    'visit' => $visit->visit_number,
                    'customer' => $stop->customer?->company_name ?? $visit->customer?->company_name ?? '—',
                    'type' => 'pitstop',
                    'lat' => (float) $stop->lat,
                    'lng' => (float) $stop->lng,
                ]);
            }

            return $points;
        })->values();
    }
}