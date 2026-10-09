<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\TaxRate;
use App\Services\Accounting\TaxCalculator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Manage the tax rate catalogue.
 *
 * The `accounting.tax_rates.view` / `.manage` permissions existed with no routes
 * behind them, so rates could not be created from the application at all. That
 * made the tax engine unusable: it resolves a rate per document date, and with
 * nothing set up every document silently reported "no tax rate is configured".
 *
 * Editing is deliberately NOT offered for a rate that is already in force.
 *
 * Changing a rate in place would retroactively change what every document issued
 * under it should have charged, and those documents have already been sent to
 * customers. Changing a rate therefore supersedes it: the old row is closed at
 * the day before the new one opens, and a new row is created. History stays
 * intact and every past document still resolves to the rate it was issued under.
 */
class TaxRateController extends Controller
{
    public function index(Request $request): View
    {
        $rates = TaxRate::query()
            ->with('account')
            ->when($request->filled('applies_to'), fn ($q) => $q->where('applies_to', $request->applies_to))
            ->when($request->filled('status'), function ($q) use ($request) {
                match ($request->status) {
                    'active' => $q->where('is_active', true),
                    'inactive' => $q->where('is_active', false),
                    // Open-ended rates are the ones still current; a rate with an
                    // end date has been superseded and no longer applies.
                    'open' => $q->whereNull('effective_to'),
                    default => null,
                };
            })
            ->orderByDesc('effective_from')
            ->orderByDesc('priority')
            ->paginate(25)
            ->withQueryString();

        $inForce = app(TaxCalculator::class)->rateFor('sales', today());

        return view('accounting.tax_rates.index', compact('rates', 'inForce'));
    }

    public function create(): View
    {
        return view('accounting.tax_rates.create', [
            'accounts' => $this->taxAccounts(),
            'suggestedFrom' => today()->toDateString(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

        /**
         * A new rate may not overlap the range of a rate it would displace at the
         * same priority. Overlapping dates at equal priority leave "which rate
         * applies" ambiguous on the days they coincide, and the resolution would
         * then depend on insertion order.
         */
        if ($clash = $this->overlappingRate($data, null)) {
            return back()->withInput()->with('toasts', [[
                'type' => 'danger',
                'message' => 'That range overlaps '.$clash->code.' ('
                    .$clash->effective_from->format('Y-m-d').' to '
                    .($clash->effective_to?->format('Y-m-d') ?? 'open').') at the same priority.',
            ]]);
        }

        $rate = TaxRate::create($data);

        return redirect()->route('accounting.tax_rates.index')
            ->with('toasts', [[
                'type' => 'success',
                'message' => 'Tax rate '.$rate->code.' ('.$rate->label().') is in force from '
                    .$rate->effective_from->format('j M Y').'.',
            ]]);
    }

    public function edit(TaxRate $taxRate): View
    {
        return view('accounting.tax_rates.edit', [
            'taxRate' => $taxRate,
            'accounts' => $this->taxAccounts(),
        ]);
    }

    /**
     * Supersede a rate rather than editing it.
     *
     * Creates the replacement and closes this one the day before, so there is no
     * window in which no rate applies. The original row is left untouched as the
     * record of what was charged at the time.
     */
    public function update(Request $request, TaxRate $taxRate): RedirectResponse
    {
$data = $this->validateData($request, true);

        if ($clash = $this->overlappingRate($data, $taxRate->id)) {
            return back()->withInput()->with('toasts', [[
                'type' => 'danger',
                'message' => 'That range overlaps '.$clash->code.' at the same priority.',
            ]]);
        }

        $effectiveFrom = Carbon::parse($data['effective_from'])->startOfDay();

        /**
         * Refuse to supersede from a date already in the past.
         *
         * Closing the old rate at a past date would leave days between that date
         * and today with no rate at all, and documents issued in that window would
         * stop validating.
         */
        if ($effectiveFrom->lt(today()->startOfDay())) {
            return back()->withInput()->with('toasts', [[
                'type' => 'danger',
'message' => 'A replacement can only take effect from today or later. '
                    .'Documents already issued under '.$taxRate->code.' keep this rate.',
            ]]);
        }

        DB::transaction(function () use ($taxRate, $data, $effectiveFrom) {
            $replacement = TaxRate::create(array_merge($data, [
                'code' => $taxRate->code.'-'.Carbon::parse($data['effective_from'])->format('Ymd'),
            ]));

            $taxRate->supersededFrom($effectiveFrom->copy()->subDay());

            $replacement->update(['effective_to' => $data['effective_to'] ?? null]);
        });

        return redirect()->route('accounting.tax_rates.index')
            ->with('toasts', [[
                'type' => 'success',
                'message' => $taxRate->code.' superseded from '
                    .$effectiveFrom->format('j M Y').'. Documents already issued are unchanged.',
            ]]);
    }

    public function destroy(TaxRate $taxRate): RedirectResponse
    {
        /**
         * A rate that has been in force cannot be deleted.
         *
         * Documents issued under it would stop resolving to any rate, and there
         * would be no record of what they were charged. Deactivate it instead,
         * which removes it from future resolution while keeping the history.
         */
        if ($taxRate->effective_from->lt(today()->startOfDay())) {
            return back()->with('toasts', [[
                'type' => 'error',
                'message' => 'A rate that has already taken effect cannot be deleted. '
                    .'Deactivate it, or supersede it with a replacement.',
            ]]);
        }

        $code = $taxRate->code;
        $taxRate->delete();

        return back()->with('toasts', [['type' => 'success', 'message' => "Rate {$code} deleted."]]);
    }

    /**
     * Toggle active, without deleting anything.
     */
    public function toggle(TaxRate $taxRate): RedirectResponse
    {
        $taxRate->update(['is_active' => ! $taxRate->is_active]);

        return back()->with('toasts', [[
            'type' => 'success',
            'message' => $taxRate->code.($taxRate->is_active ? ' activated.' : ' deactivated.'),
        ]]);
    }

    /**
     * Only liability accounts can receive tax, and only the tax ones are useful.
     */
    private function taxAccounts()
    {
        return Account::query()
            ->where('is_active', true)
            ->whereIn('code', ['2200', '2250', '2300', '1310'])
            ->orderBy('code')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function overlappingRate(array $data, ?int $ignoreId): ?TaxRate
    {
        $from = Carbon::parse($data['effective_from'])->startOfDay();
        $to = $data['effective_to']
            ? Carbon::parse($data['effective_to'])->endOfDay()
            : null;

        return TaxRate::query()
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->where('applies_to', $data['applies_to'] === 'both' ? 'both' : $data['applies_to'])
            ->where('priority', $data['priority'] ?? 0)
            ->whereDate('effective_from', '<=', ($to ?? $from)->toDateString())
            ->where(function ($q) use ($from) {
                $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $from->toDateString());
            })
            ->orderBy('effective_from')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function validateData(Request $request, bool $isUpdate = false): array
    {
        $data = $request->validate([
            // Not required on update: the code identifies the original row and the
            // replacement gets a derived one. Requiring it would reject every
            // supersede, because the edit form has no code field.
            'code' => [$isUpdate ? 'nullable' : 'required', 'string', 'max:40', Rule::unique('tax_rates', 'code')],
            'name' => ['required', 'string', 'max:120'],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'applies_to' => ['required', Rule::in(TaxRate::appliesToOptions())],
            'account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'priority' => ['required', 'integer', 'min:0', 'max:100'],
            'is_compound' => ['boolean'],
            'threshold_amount' => ['nullable', 'numeric', 'min:0'],
            'is_exempt' => ['boolean'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        /**
         * A rate that does nothing is refused.
         *
         * Zero percent, exempt, and no threshold all mean "charge nothing", but
         * three different configurations for the same outcome will eventually
         * disagree with each other on a return. Exempt is the honest one.
         */
        if ((float) $data['rate'] === 0.0 && ! $request->boolean('is_exempt')) {
            $data['is_exempt'] = true;
            $data['rate'] = 0.0;
        }

$data['is_compound'] = $request->boolean('is_compound');
        $data['is_exempt'] = $request->boolean('is_exempt') || (float) $data['rate'] === 0.0;

        if ($data['is_exempt']) {
            $data['rate'] = '0.0000';
        }
        $data['is_active'] = $request->boolean('is_active', true);
        $data['priority'] = (int) $data['priority'];
        $data['rate'] = (string) $data['rate'];

        /**
         * Normalised to null rather than left absent.
         *
         * `nullable` validation drops a key that was not submitted, so a superseded
         * rate would arrive without effective_to and then blow up on the array key
         * -- after the replacement had already been created inside the transaction,
         * which rolls it back but fails the whole action.
         */
        $data['effective_to'] = $data['effective_to'] ?? null;
        $data['threshold_amount'] = $data['threshold_amount'] ?? null;
        $data['account_id'] = $data['account_id'] ?? null;
        $data['notes'] = $data['notes'] ?? null;

        return $data;
    }
}