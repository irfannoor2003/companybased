<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\StoreFiscalPeriodRequest;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Services\Accounting\FiscalPeriodConflict;
use App\Services\Accounting\FiscalPeriodLocked;
use App\Services\Accounting\FiscalPeriodService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class FiscalPeriodController extends Controller
{
    public function __construct(private readonly FiscalPeriodService $periods) {}

    public function index(Request $request): View
    {
        $periods = FiscalPeriod::query()
            ->withCount(['journalEntries as posted_entries_count' => fn ($q) => $q->where('status', 'posted')])
            ->with('closer')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->orderByDesc('start_date')
            ->paginate(20)
            ->withQueryString();

        $summary = [
            'open' => FiscalPeriod::where('status', 'open')->count(),
            'closed' => FiscalPeriod::where('status', 'closed')->count(),
            'locked' => FiscalPeriod::where('status', 'locked')->count(),
            'unassigned' => JournalEntry::whereNull('fiscal_period_id')->count(),
        ];

        return view('accounting.fiscal_periods.index', compact('periods', 'summary'));
    }

    public function create(): View
    {
        return view('accounting.fiscal_periods.create');
    }

    public function store(StoreFiscalPeriodRequest $request): RedirectResponse
    {
        try {
            $period = $this->periods->create($request->periodAttributes());
        } catch (FiscalPeriodConflict $e) {
            return back()->withInput()->withErrors(['end_date' => $e->getMessage()]);
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['end_date' => $e->getMessage()]);
        }

        return redirect()
            ->route('accounting.fiscal_periods.index')
            ->with('status', 'Created the period '.$period->label().'.');
    }

    public function show(FiscalPeriod $fiscalPeriod): View
    {
        $fiscalPeriod->load('closer');

        $entries = JournalEntry::query()
            ->whereDate('entry_date', '>=', $fiscalPeriod->start_date)
            ->whereDate('entry_date', '<=', $fiscalPeriod->end_date)
            ->withCount('items')
            ->orderBy('entry_date')
            ->paginate(25);

        $totals = [
            'debits' => round((float) JournalEntry::query()
                ->whereDate('entry_date', '>=', $fiscalPeriod->start_date)
                ->whereDate('entry_date', '<=', $fiscalPeriod->end_date)
                ->where('status', 'posted')
                ->join('journal_entry_items', 'journal_entry_items.journal_entry_id', '=', 'journal_entries.id')
                ->sum('journal_entry_items.debit'), 2),
            'credits' => round((float) JournalEntry::query()
                ->whereDate('entry_date', '>=', $fiscalPeriod->start_date)
                ->whereDate('entry_date', '<=', $fiscalPeriod->end_date)
                ->where('status', 'posted')
                ->join('journal_entry_items', 'journal_entry_items.journal_entry_id', '=', 'journal_entries.id')
                ->sum('journal_entry_items.credit'), 2),
        ];

        return view('accounting.fiscal_periods.show', compact('fiscalPeriod', 'entries', 'totals'));
    }

    public function edit(FiscalPeriod $fiscalPeriod): View
    {
        return view('accounting.fiscal_periods.edit', compact('fiscalPeriod'));
    }

    public function update(StoreFiscalPeriodRequest $request, FiscalPeriod $fiscalPeriod): RedirectResponse
    {
        /**
         * A period's dates cannot move once it holds entries: every journal in it
         * would silently change period, and the close that was done on the old
         * range would no longer mean anything.
         */
        if ($fiscalPeriod->journalEntries()->exists()
            || JournalEntry::query()
                ->whereDate('entry_date', '>=', $fiscalPeriod->start_date)
                ->whereDate('entry_date', '<=', $fiscalPeriod->end_date)
                ->exists()) {
            return back()->withInput()->withErrors([
                'start_date' => 'This period already holds journal entries, so its dates cannot be changed. Create a correcting entry instead.',
            ]);
        }

        try {
            $this->periods->assertNoOverlap(
                Carbon::parse($request->start_date)->startOfDay(),
                Carbon::parse($request->end_date)->endOfDay(),
                $fiscalPeriod->id,
            );
        } catch (FiscalPeriodConflict $e) {
            return back()->withInput()->withErrors(['end_date' => $e->getMessage()]);
        }

        $fiscalPeriod->update($request->periodAttributes());

        return redirect()
            ->route('accounting.fiscal_periods.index')
            ->with('status', 'Updated '.$fiscalPeriod->fresh()->label().'.');
    }

    public function close(Request $request, FiscalPeriod $fiscalPeriod): RedirectResponse
    {
        try {
            $period = $this->periods->close(
                $fiscalPeriod,
                $request->user(),
                $request->input('reason'),
            );
        } catch (FiscalPeriodLocked $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Closed '.$period->label().'. No further entries can be dated in it.');
    }

    public function lock(Request $request, FiscalPeriod $fiscalPeriod): RedirectResponse
    {
        try {
            $period = $this->periods->lock(
                $fiscalPeriod,
                $request->user(),
                $request->input('reason'),
            );
        } catch (FiscalPeriodLocked|FiscalPeriodConflict $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Locked '.$period->label().'. This is final.');
    }

    public function reopen(Request $request, FiscalPeriod $fiscalPeriod): RedirectResponse
    {
        try {
            $period = $this->periods->reopen($fiscalPeriod);
        } catch (FiscalPeriodLocked $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Reopened '.$period->label().'.');
    }

    public function destroy(FiscalPeriod $fiscalPeriod): RedirectResponse
    {
        if ($fiscalPeriod->journalEntries()->exists()) {
            return back()->with('error', 'This period holds journal entries and cannot be deleted.');
        }

        $fiscalPeriod->delete();

        return redirect()
            ->route('accounting.fiscal_periods.index')
            ->with('status', 'Deleted the period.');
    }

    /**
     * Extend the calendar by whole months.
     *
     * Creating periods by hand is how gaps appear, and a gap silently stops
     * enforcing the period at that date.
     */
    public function generate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'after' => ['required', 'date'],
            'months' => ['required', 'integer', 'min:1', 'max:24'],
        ]);

        try {
            $created = $this->periods->generateMonths(
                Carbon::parse($validated['after']),
                (int) $validated['months'],
            );
        } catch (FiscalPeriodConflict $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('accounting.fiscal_periods.index')
            ->with('status', 'Created '.count($created).' periods starting '.Carbon::parse($validated['after'])->addMonthNoOverflow()->format('F Y').'.');
    }
}
