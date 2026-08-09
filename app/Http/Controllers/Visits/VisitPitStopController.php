<?php

namespace App\Http\Controllers\Visits;

use App\Http\Controllers\Controller;
use App\Models\SalesCustomer;
use App\Models\Visit;
use App\Models\VisitPitstop;
use App\Support\ExportsCsv;
use App\Support\ExportsJson;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VisitPitStopController extends Controller
{
    use ExportsCsv;
    use ExportsJson;

    public function store(Request $request, Visit $visit): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['nullable', 'integer', Rule::exists('sales_customers', 'id')],
            'purpose' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'distance_km' => ['nullable', 'numeric', 'min:0'],
            'visited_at' => ['required', 'date'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'image' => ['nullable', 'image', 'max:5120'],
        ]);

        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store("visits/{$visit->id}/pitstops", 'public');
        }

        $visit->pitStops()->create([
            'customer_id' => $data['customer_id'] ?? null,
            'purpose' => $data['purpose'] ?? null,
            'notes' => $data['notes'] ?? null,
            'distance_km' => isset($data['distance_km']) ? (string) $data['distance_km'] : null,
            'image_path' => $imagePath,
            'visited_at' => $data['visited_at'],
            'lat' => $data['lat'] ?? null,
            'lng' => $data['lng'] ?? null,
        ]);

        return back()->with('toasts', [['type' => 'success', 'message' => 'Pit-stop added to the visit.']]);
    }

    public function update(Request $request, Visit $visit, VisitPitstop $pitstop): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['nullable', 'integer', Rule::exists('sales_customers', 'id')],
            'purpose' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'distance_km' => ['nullable', 'numeric', 'min:0'],
            'visited_at' => ['required', 'date'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'image' => ['nullable', 'image', 'max:5120'],
        ]);

        if ($request->hasFile('image')) {
            if ($pitstop->image_path) {
                Storage::disk('public')->delete($pitstop->image_path);
            }
            $pitstop->image_path = $request->file('image')->store("visits/{$visit->id}/pitstops", 'public');
        }

        $pitstop->customer_id = $data['customer_id'] ?? null;
        $pitstop->purpose = $data['purpose'] ?? null;
        $pitstop->notes = $data['notes'] ?? null;
        $pitstop->distance_km = isset($data['distance_km']) ? (string) $data['distance_km'] : null;
        $pitstop->visited_at = $data['visited_at'];
        $pitstop->lat = $data['lat'] ?? null;
        $pitstop->lng = $data['lng'] ?? null;
        $pitstop->save();

        return back()->with('toasts', [['type' => 'success', 'message' => 'Pit-stop updated.']]);
    }

    public function destroy(Visit $visit, VisitPitstop $pitstop): RedirectResponse
    {
        if ($pitstop->image_path) {
            Storage::disk('public')->delete($pitstop->image_path);
        }

        $pitstop->delete();

        return back()->with('toasts', [['type' => 'success', 'message' => 'Pit-stop removed.']]);
    }

    public function image(VisitPitstop $pitstop): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        abort_unless($pitstop->image_path && Storage::disk('public')->exists($pitstop->image_path), 404);

        return response()->file(Storage::disk('public')->path($pitstop->image_path));
    }

    public function export(Request $request, Visit $visit): StreamedResponse
    {
        $rows = $visit->pitStops()->with('customer')->get()->map(fn (VisitPitstop $p) => [
            'customer' => $p->customer?->company_name,
            'purpose' => $p->purpose,
            'visited_at' => $p->visited_at?->format('Y-m-d H:i'),
            'distance_km' => $p->distance_km,
            'lat' => $p->lat,
            'lng' => $p->lng,
        ]);

        $filename = $visit->visit_number.'-pitstops.'.($request->query('format') === 'json' ? 'json' : 'csv');

        return $request->query('format') === 'json'
            ? $this->streamJson($filename, $rows)
            : $this->streamCsv($filename, ['Customer', 'Purpose', 'Visited at', 'Distance km', 'Lat', 'Lng'], $rows->values());
    }
}