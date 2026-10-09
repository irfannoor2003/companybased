<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\InventoryWarehouse;
use App\Services\Inventory\MaterialRequirementsService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Material requirements planning screen.
 *
 * Read-only. This screen tells a planner what to buy or make; turning that into a
 * purchase order is a deliberate second step, because a plan generated from
 * unconfirmed demand should never place an order on its own.
 */
class MaterialRequirementsController extends Controller
{
    public function index(Request $request): View
    {
        // Default horizon is 90 days. Longer and the plan is dominated by material
        // for demand too far out to act on; shorter and real shortages get missed
        // behind goods already in transit.
        $horizon = $request->filled('horizon')
            ? Carbon::parse($request->input('horizon'))
            : Carbon::today()->addDays(90);

        $warehouse = $request->filled('warehouse')
            ? InventoryWarehouse::find($request->integer('warehouse'))
            : null;

        $planner = new MaterialRequirementsService($warehouse?->id);

        $plan = $planner->plan($horizon);
        $summary = MaterialRequirementsService::summarise($plan);

        return view('inventory.material-requirements.index', [
            'plan' => $plan,
            'summary' => $summary,
            'horizon' => $horizon,
            'warehouse' => $warehouse,
            'warehouses' => InventoryWarehouse::where('is_active', true)->orderBy('name')->get(),
        ]);
    }
}