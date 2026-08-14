<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Support\ExportsCsv;
use App\Support\GeneralLedger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class JournalController extends Controller
{
    use ExportsCsv;

    public function index(Request $request): View
    {
        $entries = JournalEntry::query()
            ->withCount('items')
            ->with('creator')
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('from'), fn ($q) => $q->where('entry_date', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->where('entry_date', '<=', $request->to))
            ->orderByDesc('entry_date')
            ->paginate(20)
            ->withQueryString();

        $totals = [
            'debits' => round((float) $entries->sum(fn (JournalEntry $e) => $e->totalDebits()), 2),
            'credits' => round((float) $entries->sum(fn (JournalEntry $e) => $e->totalCredits()), 2),
        ];

        return view('accounting.journal.index', compact('entries', 'totals'));
    }

    public function create(): View
    {
        return view('accounting.journal.create', [
            'accounts' => Account::query()->active()->orderBy('code')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'entry_date' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:5000'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['required', 'integer'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.memo' => ['nullable', 'string', 'max:255'],
        ]);

        $entry = JournalEntry::create([
            'number' => next_document_number('journal_entry', 'JE'),
            'entry_date' => $data['entry_date'],
            'reference' => $data['reference'] ?? null,
            'description' => $data['description'] ?? null,
            'status' => 'draft',
            'created_by' => auth()->id(),
        ]);

        GeneralLedger::replaceLines($entry, $data['lines']);

        if (! $entry->isBalanced()) {
            return redirect()->route('accounting.journal.edit', $entry)
                ->with('toasts', [['type' => 'error', 'message' => 'Entry saved as draft. Debits must equal credits before posting.']]);
        }

        return redirect()->route('accounting.journal.show', $entry)
            ->with('toasts', [['type' => 'success', 'message' => "Journal entry {$entry->number} saved."]]);
    }

    public function show(JournalEntry $entry): View
    {
        $entry->load(['items.account', 'creator']);

        return view('accounting.journal.show', compact('entry'));
    }

    public function edit(JournalEntry $entry): View
    {
        $entry->load('items');

        return view('accounting.journal.edit', [
            'entry' => $entry,
            'accounts' => Account::query()->active()->orderBy('code')->get(),
        ]);
    }

    public function update(Request $request, JournalEntry $entry): RedirectResponse
    {
        if ($entry->status !== 'draft') {
            return back()->with('toasts', [['type' => 'error', 'message' => 'Only draft entries can be edited.']]);
        }

        $data = $request->validate([
            'entry_date' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:5000'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['required', 'integer'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.memo' => ['nullable', 'string', 'max:255'],
        ]);

        $entry->update([
            'entry_date' => $data['entry_date'],
            'reference' => $data['reference'] ?? null,
            'description' => $data['description'] ?? null,
        ]);

        GeneralLedger::replaceLines($entry, $data['lines']);

        $toast = $entry->isBalanced()
            ? ['type' => 'success', 'message' => "Journal entry {$entry->number} updated."]
            : ['type' => 'error', 'message' => 'Entry saved. It is not balanced — debits must equal credits to post.'];

        return back()->with('toasts', [$toast]);
    }

    public function post(JournalEntry $entry): RedirectResponse
    {
        try {
            GeneralLedger::post($entry);
        } catch (\RuntimeException $e) {
            return back()->with('toasts', [['type' => 'error', 'message' => $e->getMessage()]]);
        }

        return redirect()->route('accounting.journal.show', $entry)
            ->with('toasts', [['type' => 'success', 'message' => "Journal entry {$entry->number} posted."]]);
    }

    public function void(JournalEntry $entry): RedirectResponse
    {
        try {
            GeneralLedger::void($entry);
        } catch (\RuntimeException $e) {
            return back()->with('toasts', [['type' => 'error', 'message' => $e->getMessage()]]);
        }

        return back()->with('toasts', [['type' => 'success', 'message' => "Journal entry {$entry->number} voided."]]);
    }

    public function destroy(JournalEntry $entry): RedirectResponse
    {
        $entry->delete();

        return redirect()->route('accounting.journal.index')
            ->with('toasts', [['type' => 'success', 'message' => "Journal entry {$entry->number} deleted."]]);
    }

    public function export(Request $request): StreamedResponse
    {
        $entries = JournalEntry::query()
            ->with('items.account')
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('from'), fn ($q) => $q->where('entry_date', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->where('entry_date', '<=', $request->to))
            ->orderByDesc('entry_date')
            ->get();

        return $this->streamCsv('journal-entries-'.now()->format('Y-m-d').'.csv', ['Number', 'Date', 'Status', 'Account', 'Debit', 'Credit', 'Memo'], $entries->flatMap(fn (JournalEntry $e) => $e->items->map(fn ($i) => [
            $e->number,
            $e->entry_date->format('Y-m-d'),
            ucfirst($e->status),
            $i->account?->name,
            $i->debit,
            $i->credit,
            $i->memo,
        ])));
    }
}