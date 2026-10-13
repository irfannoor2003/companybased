<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\InventoryWorkCentre;
use App\Services\Inventory\RoutingService;
use App\Support\ExportsCsv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Work centres, and the load on them.
 *
 * A work centre is a place work happens -- a machine, a bench, a person -- and the
 * record that turns "that job took a while" into a schedule. Nothing like it existed
 * here before: overhead was absorbed on direct labour hours because a machine-hour
 * rate would have had nothing to measure against.
 */
class WorkCentreController extends Controller
{
    use ExportsCsv;

    public function index(Request $request): View
    {
        $centres = InventoryWorkCentre::query()
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$request->search}%")
                ->orWhere('code', 'like', "%{$request->search}%")))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->when($request->boolean('only_active'), fn ($q) => $q->where('is_active', true))
            ->orderBy('code')
            ->paginate(20)
            ->withQueryString();

        return view('inventory.work_centres.index', [
            'centres' => $centres,
            'types' => InventoryWorkCentre::typeOptions(),
        ]);
    }

    public function create(): View
    {
        return view('inventory.work_centres.create', ['types' => InventoryWorkCentre::typeOptions()]);
    }

    public function store(Request $request): RedirectResponse
    {
        InventoryWorkCentre::create($this->validateData($request));

        return redirect()->route('inventory.work_centres.index')
            ->with('toasts', [['type' => 'success', 'message' => 'Work centre created.']]);
    }

    public function edit(InventoryWorkCentre $workCentre): View
    {
        return view('inventory.work_centres.edit', [
            'centre' => $workCentre,
            'types' => InventoryWorkCentre::typeOptions(),
        ]);
    }

    public function update(Request $request, InventoryWorkCentre $workCentre): RedirectResponse
    {
        $workCentre->update($this->validateData($request, $workCentre));

        return redirect()->route('inventory.work_centres.index')
            ->with('toasts', [['type' => 'success', 'message' => 'Work centre updated.']]);
    }

    public function destroy(InventoryWorkCentre $workCentre): RedirectResponse
    {
        if ($workCentre->orderOperations()->exists()) {
            return back()->with('toasts', [[
                'type' => 'error',
                'message' => 'This work centre is used by production operations and cannot be deleted. '
                    .'Deactivate it instead -- a retired centre stops taking new work while its history stays.',
            ]]);
        }

        $workCentre->delete();

        return redirect()->route('inventory.work_centres.index')
            ->with('toasts', [['type' => 'success', 'message' => 'Work centre deleted.']]);
    }

    /**
     * How loaded each centre is on a given day.
     *
     * The point of the whole record: a centre's capacity is a daily number, and work
     * is committed against it, so overloading a machine becomes visible before the
     * job is late rather than after.
     */
    public function capacity(Request $request, RoutingService $routing): View
    {
        /**
         * `date()` returns null when the key is absent, and its second argument is a
         * FORMAT, not a default -- so a default passed there is silently used as the
         * date format and the date comes back null.
         */
        $date = $request->date('date') ?? today()->toDateString();

        $day = \Illuminate\Support\Carbon::parse($date);

        return view('inventory.work_centres.capacity', [
            'date' => $day,
            'rows' => $routing->capacityFor($day),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $centres = InventoryWorkCentre::query()->orderBy('code')->get();

        return $this->streamCsv('work-centres-'.today()->format('Y-m-d').'.csv', [
            'Code', 'Name', 'Type', 'Hourly rate', 'Minutes per day', 'Location', 'Active',
        ], $centres->map(fn (InventoryWorkCentre $c) => [
            $c->code,
            $c->name,
            $c->type,
            $c->hourly_rate,
            $c->capacity_minutes_per_day ?? '',
            $c->location,
            $c->is_active ? 'Yes' : 'No',
        ]));
    }

    private function validateData(Request $request, ?InventoryWorkCentre $centre = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:50', Rule::unique('inventory_work_centres')->ignore($centre?->id)],
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::in(InventoryWorkCentre::typeOptions())],
            'hourly_rate' => ['required', 'numeric', 'min:0'],
            'capacity_minutes_per_day' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'location' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }
}