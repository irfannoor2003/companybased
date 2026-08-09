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
        $visits = Visit::query()
            ->with(['customer', 'salesRep'])
            ->withCount('pitStops')
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('sales_rep_id'), fn ($q) => $q->where('sales_rep_id', $request->sales_rep_id))
            ->when($request->filled('from'), fn ($q) => $q->where('scheduled_at', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->where('scheduled_at', '<=', $request->to))
            ->orderByDesc('scheduled_at')
            ->paginate(20)
            ->withQueryString();

        $salesReps = Employee::query()->where('employment_status', 'active')->orderBy('first_name')->get(['id', 'first_name', 'last_name']);

        $pending = Visit::query()->where('status', 'pending')->count();
        $started = Visit::query()->where('status', 'started')->count();
        $completed = Visit::query()->where('status', 'completed')->count();
        $today = Visit::query()->whereDate('scheduled_at', now()->toDateString())->count();

        return view('visits.index', compact('visits', 'salesReps', 'pending', 'started', 'completed', 'today'));
    }

    public function create(): View
    {
        return view('visits.create', [
            'customers' => SalesCustomer::query()->orderBy('company_name')->get(['id', 'company_name', 'contact_name']),
            'salesReps' => Employee::query()->where('employment_status', 'active')->orderBy('first_name')->get(['id', 'first_name', 'last_name']),
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
            'notes' => $data['notes'] ?? null,
            'status' => 'pending',
            'scheduled_at' => $data['scheduled_at'],
            'start_lat' => $data['start_lat'] ?? null,
            'start_lng' => $data['start_lng'] ?? null,
        ]);

        return redirect()->route('visits.show', $visit)
            ->with('toasts', [['type' => 'success', 'message' => "Visit {$visit->visit_number} created."]]);
    }

    public function show(Visit $visit): View
    {
        $visit->load(['customer', 'salesRep', 'pitStops.customer']);

        $customers = SalesCustomer::query()->orderBy('company_name')->get(['id', 'company_name', 'contact_name']);

        return view('visits.show', compact('visit', 'customers'));
    }

    public function edit(Visit $visit): View
    {
        return view('visits.edit', [
            'visit' => $visit,
            'customers' => SalesCustomer::query()->orderBy('company_name')->get(['id', 'company_name', 'contact_name']),
            'salesReps' => Employee::query()->where('employment_status', 'active')->orderBy('first_name')->get(['id', 'first_name', 'last_name']),
        ]);
    }

    public function update(Request $request, Visit $visit): RedirectResponse
    {
        $data = $this->validateData($request);

        $visit->update([
            'customer_id' => $data['customer_id'] ?? null,
            'sales_rep_id' => $data['sales_rep_id'] ?? null,
            'purpose' => $data['purpose'] ?? null,
            'notes' => $data['notes'] ?? null,
            'scheduled_at' => $data['scheduled_at'],
            'start_lat' => $data['start_lat'] ?? null,
            'start_lng' => $data['start_lng'] ?? null,
        ]);

        return back()->with('toasts', [['type' => 'success', 'message' => "Visit {$visit->visit_number} updated."]]);
    }

    public function destroy(Visit $visit): RedirectResponse
    {
        $visit->delete();

        return redirect()->route('visits.index')
            ->with('toasts', [['type' => 'success', 'message' => "Visit {$visit->visit_number} deleted."]]);
    }

    public function start(Visit $visit): RedirectResponse
    {
        if ($visit->status !== 'pending') {
            return back()->with('toasts', [['type' => 'danger', 'message' => 'Only pending visits can be started.']]);
        }

        $visit->update(['status' => 'started', 'started_at' => now()]);

        return back()->with('toasts', [['type' => 'success', 'message' => "Visit {$visit->visit_number} started."]]);
    }

    public function complete(Request $request, Visit $visit): RedirectResponse
    {
        if ($visit->status !== 'started') {
            return back()->with('toasts', [['type' => 'danger', 'message' => 'Only started visits can be completed.']]);
        }

        $data = $request->validate([
            'outcome' => ['required', Rule::in(Visit::outcomeOptions())],
            'outcome_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $visit->update([
            'status' => 'completed',
            'completed_at' => now(),
            'outcome' => $data['outcome'],
            'outcome_notes' => $data['outcome_notes'] ?? null,
            'distance_km' => (string) $visit->totalDistanceKm(),
        ]);

        return back()->with('toasts', [['type' => 'success', 'message' => "Visit {$visit->visit_number} completed."]]);
    }

    public function cancel(Request $request, Visit $visit): RedirectResponse
    {
        if (! in_array($visit->status, ['pending', 'started'], true)) {
            return back()->with('toasts', [['type' => 'danger', 'message' => 'This visit cannot be cancelled.']]);
        }

        $data = $request->validate([
            'cancel_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $visit->update([
            'status' => 'cancelled',
            'notes' => trim(($visit->notes ?? '').PHP_EOL.'Cancelled: '.($data['cancel_reason'] ?? 'No reason given.')),
        ]);

        return back()->with('toasts', [['type' => 'success', 'message' => "Visit {$visit->visit_number} cancelled."]]);
    }

    public function export(Request $request): StreamedResponse
    {
        $visits = Visit::query()
            ->with(['customer', 'salesRep'])
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('sales_rep_id'), fn ($q) => $q->where('sales_rep_id', $request->sales_rep_id))
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
        ]);

        $filename = 'visits-'.now()->format('Y-m-d').'.'.($request->query('format') === 'json' ? 'json' : 'csv');

        return $request->query('format') === 'json'
            ? $this->streamJson($filename, $rows)
            : $this->streamCsv($filename, ['Visit', 'Customer', 'Sales rep', 'Scheduled', 'Purpose', 'Status', 'Outcome', 'Distance km', 'Pit stops'], $rows->values());
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'customer_id' => ['nullable', 'integer', Rule::exists('sales_customers', 'id')],
            'sales_rep_id' => ['nullable', 'integer', Rule::exists('employees', 'id')],
            'purpose' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'scheduled_at' => ['required', 'date'],
            'start_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'start_lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);
    }
}