<?php

namespace App\Http\Controllers\Visits;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\SalesCustomer;
use App\Models\Visit;
use App\Support\ExportsCsv;
use App\Support\ExportsJson;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VisitsController extends Controller
{
    use ExportsCsv;
    use ExportsJson;

    public function index(Request $request): View
    {
        $salesmanEmployeeId = $this->salesmanScopeId();
        $isSalesman = $this->isSalesman();

        $visits = Visit::query()
            ->with(['customer', 'salesRep'])
            ->withCount('pitStops')
            ->when($salesmanEmployeeId, fn ($q) => $q->where('sales_rep_id', $salesmanEmployeeId))
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when(! $salesmanEmployeeId && $request->filled('sales_rep_id'), fn ($q) => $q->where('sales_rep_id', $request->sales_rep_id))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('scheduled_at', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('scheduled_at', '<=', $request->to))
            ->orderByDesc('scheduled_at')
            ->paginate(20)
            ->withQueryString();

        $salesReps = Employee::query()->where('employment_status', 'active')->orderBy('first_name')->get(['id', 'first_name', 'last_name']);

        $baseQuery = Visit::query()->when($salesmanEmployeeId, fn ($q) => $q->where('sales_rep_id', $salesmanEmployeeId));
        $pending = (clone $baseQuery)->where('status', 'pending')->count();
        $started = (clone $baseQuery)->where('status', 'started')->count();
        $completed = (clone $baseQuery)->where('status', 'completed')->count();
        $today = (clone $baseQuery)->whereDate('scheduled_at', now()->toDateString())->count();

        return view('visits.index', compact('visits', 'salesReps', 'pending', 'started', 'completed', 'today', 'isSalesman'));
    }

    public function create(): View
    {
        return view('visits.create', [
            'customers' => SalesCustomer::query()->orderBy('company_name')->get(['id', 'company_name', 'contact_name']),
            'salesReps' => Employee::query()->where('employment_status', 'active')->orderBy('first_name')->get(['id', 'first_name', 'last_name']),
            'defaultRepId' => $this->salesmanScopeId(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

        $visit = Visit::create([
            'visit_number' => next_document_number('visit', 'VIS'),
            'customer_id' => $data['customer_id'] ?? null,
            'sales_rep_id' => $data['sales_rep_id'] ?? null,
            'purpose' => $data['purpose'] ?? null,
            'status' => 'pending',
            'scheduled_at' => $data['scheduled_at'],
        ]);

        return redirect()->route('visits.show', $visit)
            ->with('toasts', [['type' => 'success', 'message' => "Visit {$visit->visit_number} created."]]);
    }

    public function show(Visit $visit): View
    {
        $this->authorizeVisitAccess($visit);

        $visit->load(['customer', 'salesRep', 'pitStops.customer']);

        $customers = SalesCustomer::query()->orderBy('company_name')->get(['id', 'company_name', 'contact_name']);

        return view('visits.show', compact('visit', 'customers'));
    }

    public function edit(Visit $visit): View
    {
        $this->authorizeVisitAccess($visit);

        return view('visits.edit', [
            'visit' => $visit,
            'customers' => SalesCustomer::query()->orderBy('company_name')->get(['id', 'company_name', 'contact_name']),
            'salesReps' => Employee::query()->where('employment_status', 'active')->orderBy('first_name')->get(['id', 'first_name', 'last_name']),
        ]);
    }

    public function update(Request $request, Visit $visit): RedirectResponse
    {
        $this->authorizeVisitAccess($visit);

        $data = $this->validateData($request);

        $visit->update([
            'customer_id' => $data['customer_id'] ?? null,
            'sales_rep_id' => $data['sales_rep_id'] ?? null,
            'purpose' => $data['purpose'] ?? null,
            'scheduled_at' => $data['scheduled_at'],
        ]);

        return back()->with('toasts', [['type' => 'success', 'message' => "Visit {$visit->visit_number} updated."]]);
    }

    public function destroy(Visit $visit): RedirectResponse
    {
        $visit->delete();

        return redirect()->route('visits.index')
            ->with('toasts', [['type' => 'success', 'message' => "Visit {$visit->visit_number} deleted."]]);
    }

    public function start(Request $request, Visit $visit): RedirectResponse
    {
        $this->authorizeVisitAccess($visit);

        if ($visit->status !== 'pending') {
            return back()->with('toasts', [['type' => 'danger', 'message' => 'Only pending visits can be started.']]);
        }

        if ($mismatch = $this->locationMismatch($request)) {
            return back()->with('toasts', [['type' => 'danger', 'message' => $mismatch]]);
        }

        $visit->update([
            'status' => 'started',
            'started_at' => now(),
            'start_lat' => $request->latitude,
            'start_lng' => $request->longitude,
        ]);

        return back()->with('toasts', [['type' => 'success', 'message' => "Visit {$visit->visit_number} started."]]);
    }

    public function complete(Request $request, Visit $visit): RedirectResponse
    {
        $this->authorizeVisitAccess($visit);

        if ($visit->status !== 'started') {
            return back()->with('toasts', [['type' => 'danger', 'message' => 'Only started visits can be completed.']]);
        }

        $request->validate([
            'outcome' => ['nullable', Rule::in(Visit::outcomeOptions())],
            'distance_km' => ['required', 'numeric', 'min:0', 'max:100000'],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);

        if ($mismatch = $this->locationMismatch($request)) {
            return back()->with('toasts', [['type' => 'danger', 'message' => $mismatch]]);
        }

        $visit->update([
            'status' => 'completed',
            'completed_at' => now(),
            'outcome' => $request->filled('outcome') ? $request->outcome : null,
            'outcome_notes' => $request->note ?? null,
            'distance_km' => (string) $request->distance_km,
        ]);

        return back()->with('toasts', [['type' => 'success', 'message' => "Visit {$visit->visit_number} completed."]]);
    }

    /**
     * Calculate distance between two points using the Haversine formula.
     * Returns distance in meters.
     */
    private function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000; // meters

        $latDelta = deg2rad($lat2 - $lat1);
        $lngDelta = deg2rad($lng2 - $lng1);

        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($lngDelta / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    public function cancel(Request $request, Visit $visit): RedirectResponse
    {
        $this->authorizeVisitAccess($visit);

        if (! in_array($visit->status, ['pending', 'started'], true)) {
            return back()->with('toasts', [['type' => 'danger', 'message' => 'This visit cannot be cancelled.']]);
        }

        if ($mismatch = $this->locationMismatch($request)) {
            return back()->with('toasts', [['type' => 'danger', 'message' => $mismatch]]);
        }

        $data = $request->validate([
            'cancel_reason' => ['required', 'string', 'max:2000'],
        ]);

        $visit->update([
            'status' => 'cancelled',
            'notes' => trim(($visit->notes ?? '').PHP_EOL.'Cancelled: '.$data['cancel_reason']),
        ]);

        return back()->with('toasts', [['type' => 'success', 'message' => "Visit {$visit->visit_number} cancelled."]]);
    }

    public function export(Request $request): StreamedResponse
    {
        $salesmanEmployeeId = $this->salesmanScopeId();

        $visits = Visit::query()
            ->with(['customer', 'salesRep'])
            ->when($salesmanEmployeeId, fn ($q) => $q->where('sales_rep_id', $salesmanEmployeeId))
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when(! $salesmanEmployeeId && $request->filled('sales_rep_id'), fn ($q) => $q->where('sales_rep_id', $request->sales_rep_id))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('scheduled_at', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('scheduled_at', '<=', $request->to))
            ->orderByDesc('scheduled_at')
            ->get();

        $rows = $visits->map(fn (Visit $v) => [
            'visit_number' => $v->visit_number,
            'customer' => $v->customer?->company_name,
            'sales_rep' => $v->salesRep?->fullName(),
            'scheduled_at' => $v->scheduled_at?->format('Y-m-d'),
            'purpose' => $v->purpose,
            'status' => ucfirst($v->status),
            'outcome' => $v->outcome ? ucfirst(str_replace('_', ' ', $v->outcome)) : null,
            'distance_km' => $v->distance_km ?? $v->totalDistanceKm(),
            'pitstops' => $v->pit_stops_count ?? $v->pitStops()->count(),
        ])->values();

        $rows = $rows->push([
            'visit_number' => 'TOTAL',
            'customer' => null,
            'sales_rep' => null,
            'scheduled_at' => null,
            'purpose' => 'Mileage total',
            'status' => null,
            'outcome' => null,
            'distance_km' => (string) $rows->sum('distance_km'),
            'pitstops' => $rows->sum('pitstops'),
        ]);

        $filename = 'visits-'.now()->format('Y-m-d').'.'.($request->query('format') === 'json' ? 'json' : 'csv');

        return $request->query('format') === 'json'
            ? $this->streamJson($filename, $rows)
            : $this->streamCsv($filename, ['Visit', 'Customer', 'Sales rep', 'Scheduled', 'Purpose', 'Status', 'Outcome', 'Distance km', 'Pit stops'], $rows);
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'customer_id' => ['nullable', 'integer', Rule::exists('sales_customers', 'id')],
            'sales_rep_id' => ['nullable', 'integer', Rule::exists('employees', 'id')],
            'purpose' => ['required', 'string', 'max:255'],
            'scheduled_at' => ['required', 'date'],
        ]);
    }

    /**
     * Whether the current user is a Salesman (only sees and manages their own visits).
     */
    private function isSalesman(): bool
    {
        $user = auth()->user();

        return $user && ! $user->isAdmin() && $user->hasRole('Salesman');
    }

    /**
     * Employee id to scope visits to when the current user is a salesman.
     * Returns null for admin/super admin and non-salesman roles.
     */
    private function salesmanScopeId(): ?int
    {
        if (! $this->isSalesman()) {
            return null;
        }

        return (int) (Employee::query()->where('user_id', auth()->id())->value('id') ?? 0) ?: null;
    }

    /**
     * Block a salesman from accessing another rep's visit.
     */
    private function authorizeVisitAccess(Visit $visit): void
    {
        if (! $this->isSalesman()) {
            return;
        }

        $employeeId = (int) (Employee::query()->where('user_id', auth()->id())->value('id') ?? 0);

        abort_if((int) $visit->sales_rep_id !== $employeeId, 403, 'You can only manage your own visits.');
    }

    /**
     * Validate the browser-provided geolocation and compare it against the
     * company location set by the admin. Returns a "location mismatched"
     * message when the salesman is outside the allowed radius, or null when
     * the location matches.
     */
    private function locationMismatch(Request $request): ?string
    {
        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $officeLat = (float) settings('company.latitude', 0);
        $officeLng = (float) settings('company.longitude', 0);
        $radius = (float) settings('company.radius', 500);

        $distance = $this->haversineDistance(
            (float) $data['latitude'],
            (float) $data['longitude'],
            $officeLat,
            $officeLng
        );

        if ($distance > $radius) {
            return 'Location mismatched. You are '.number_format($distance, 0).' m from the company location (allowed '.number_format($radius, 0).' m).';
        }

        return null;
    }
}