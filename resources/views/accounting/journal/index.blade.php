<x-app-layout :pageTitle="'Journal entries'">
    <x-slot name="header">
        <x-page-header title="Journal" description="Chronological record of double-entry postings." icon="document">
            <x-slot name="actions">
                @if (auth()->user()->can('accounting.journal_entries.export'))
                    <x-export route="accounting.journal.export" />
                @endif
                @if (auth()->user()->can('accounting.journal_entries.create'))
                    <x-button href="{{ route('accounting.journal.create') }}" icon="plus">New entry</x-button>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    @include('accounting._tabs')

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-stat-card label="Entries shown" :value="$entries->total()" icon="document" tone="primary" />
        <x-stat-card label="Total debits" :value="money($totals['debits'])" icon="arrow-down" tone="info" />
        <x-stat-card label="Total credits" :value="money($totals['credits'])" icon="arrow-up" tone="warning" />
    </div>

    <div class="mt-6">
        <x-card :padding="false">
            <form method="GET" action="{{ route('accounting.journal.index') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
                <div class="min-w-[220px] flex-1">
                    <x-input name="search" label="Search" placeholder="Number, reference, description…" leadingIcon="search"
                        value="{{ request('search') }}" size="sm" />
                </div>
                <div class="w-36">
                    <x-select name="status" label="Status" size="sm">
                        <option value="">Any status</option>
                        @foreach (\App\Models\JournalEntry::statusOptions() as $status)
                            <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
                        @endforeach
                    </x-select>
                </div>
                <div>
                    <x-input name="from" label="From" type="date" value="{{ request('from') }}" size="sm" />
                </div>
                <div>
                    <x-input name="to" label="To" type="date" value="{{ request('to') }}" size="sm" />
                </div>
                <div class="flex gap-2">
                    <x-button type="submit" size="sm" icon="filter">Filter</x-button>
                    @if (request()->hasAny(['search', 'status', 'from', 'to']))
                        <x-button href="{{ route('accounting.journal.index') }}" variant="ghost" size="sm">Clear</x-button>
                    @endif
                </div>
            </form>

            @if ($entries->isEmpty())
                <x-empty-state icon="document" title="No journal entries" description="Create a journal entry to start posting to the general ledger." />
            @else
                <div class="table-wrap !border-0 !rounded-none">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th>Number</th>
                                <th>Date</th>
                                <th>Reference</th>
                                <th>Description</th>
                                <th>Status</th>
                                <th class="text-right">Debits</th>
                                <th class="text-right">Credits</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($entries as $entry)
                                <tr>
                                    <td>
                                        <a href="{{ route('accounting.journal.show', $entry) }}" class="font-mono text-xs font-medium text-primary hover:underline">
                                            {{ $entry->number }}
                                        </a>
                                    </td>
                                    <td class="text-ink-soft">{{ $entry->entry_date->format('Y-m-d') }}</td>
                                    <td class="text-ink-soft">{{ $entry->reference ?: '—' }}</td>
                                    <td class="max-w-xs truncate text-ink-soft">{{ $entry->description ?: '—' }}</td>
                                    <td><x-accounting.status-badge :status="$entry->status" /></td>
                                    <td class="text-right text-ink">{{ money($entry->totalDebits()) }}</td>
                                    <td class="text-right text-ink">{{ money($entry->totalCredits()) }}</td>
                                    <td class="text-right">
                                        <div class="flex items-center justify-end gap-1">
                                            <a href="{{ route('accounting.journal.show', $entry) }}" class="btn-ghost btn-icon btn-sm" title="View">
                                                <x-icon name="eye" class="size-4" />
                                            </a>
                                            @if (auth()->user()->can('accounting.journal_entries.edit') && $entry->status === 'draft')
                                                <a href="{{ route('accounting.journal.edit', $entry) }}" class="btn-ghost btn-icon btn-sm" title="Edit">
                                                    <x-icon name="edit" class="size-4" />
                                                </a>
                                            @endif
                                            @if (auth()->user()->can('accounting.journal_entries.delete') && $entry->status === 'draft')
                                                <form method="POST" action="{{ route('accounting.journal.destroy', $entry) }}"
                                                    onsubmit="return confirm('Delete entry {{ $entry->number }}?');">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn-ghost btn-icon btn-sm text-rose-500" title="Delete">
                                                        <x-icon name="trash" class="size-4" />
                                                    </button>
                                                </form>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($entries->hasPages())
                <div class="px-5 py-4">
                    {{ $entries->links() }}
                </div>
            @endif
        </x-card>
    </div>
</x-app-layout>
