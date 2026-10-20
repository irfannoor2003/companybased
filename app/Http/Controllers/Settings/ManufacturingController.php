<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\InventoryWorkCentre;
use App\Models\Setting;
use App\Services\Accounting\ProductionAbsorptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Manufacturing costing settings.
 *
 * Absorption used to be labour-hour only, and its own docblock said why: there was
 * no work centre record in the application for a machine-hour rate to measure
 * against. That reason expired when work centres arrived -- but the BASIS was not
 * switched underneath existing users, because the two bases value the same job
 * differently and silently repricing live inventory is a change nobody should have
 * to discover in a margin report.
 *
 * So both live, the business picks, and this screen exists so picking is a decision
 * rather than a database edit.
 */
class ManufacturingController extends Controller
{
    public function index(): View
    {
        $service = app(ProductionAbsorptionService::class);

        $basis = $service->basis();

        /**
         * Counted here rather than left as prose, because "the machine-hour basis is
         * available" is only true if some centre actually carries a rate. A shop with
         * no rates configured can select it and would silently absorb nothing at all,
         * which looks exactly like absorption working.
         */
        $ratedCentres = InventoryWorkCentre::where('is_active', true)
            ->where('hourly_rate', '>', 0)
            ->count();

        $activeCentres = InventoryWorkCentre::where('is_active', true)->count();

        return view('settings.manufacturing', [
            'basis' => $basis,
            'bases' => [
                ProductionAbsorptionService::BASIS_LABOUR_HOUR => 'Direct labour hour',
                ProductionAbsorptionService::BASIS_WORK_CENTRE_HOUR => 'Work centre hour',
            ],
            'monthlyHours' => $service->standardMonthlyHours(),
            'overheadRate' => (float) Setting::get('production.overhead_rate_per_hour', 0),
            'ratedCentres' => $ratedCentres,
            'activeCentres' => $activeCentres,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorizePermission('settings.manufacturing.manage');

        $data = $request->validate([
            'absorption_basis' => ['required', Rule::in(ProductionAbsorptionService::ABSORPTION_BASES)],
            'standard_monthly_hours' => ['required', 'numeric', 'min:1', 'max:744'],
            'overhead_rate_per_hour' => ['required', 'numeric', 'min:0', 'max:1000000'],
        ]);

        /**
         * Refuse a basis the shop cannot actually use, rather than accepting it and
         * absorbing nothing.
         *
         * Selecting `work_centre_hour` with no active centre carrying a rate would
         * post no overhead at all while the screen reported the setting as
         * `work_centre_hour` — a configuration that reads as correct and books
         * nothing. Blocking it here makes the mismatch impossible to create.
         */
        if ($data['absorption_basis'] === ProductionAbsorptionService::BASIS_WORK_CENTRE_HOUR) {
            $rated = InventoryWorkCentre::where('is_active', true)->where('hourly_rate', '>', 0)->count();

            if ($rated === 0) {
                return back()
                    ->withInput()
                    ->with('toasts', [[
                        'type' => 'error',
                        'message' => 'No active work centre has an hourly rate, so overhead on a work-centre-hour basis would be zero. '
                            .'Give at least one centre a rate first, or keep the labour-hour basis.',
                    ]]);
            }
        }

        Setting::setMany([
            'production.absorption_basis' => $data['absorption_basis'],
            'production.standard_monthly_hours' => (string) $data['standard_monthly_hours'],
            'production.overhead_rate_per_hour' => (string) $data['overhead_rate_per_hour'],
        ], 'production');

        return back()->with('toasts', [[
            'type' => 'success',
            'message' => 'Manufacturing costing settings updated. These apply to orders absorbed from now on; '
                .'work already in progress keeps the basis it was absorbed on.',
        ]]);
    }
}
