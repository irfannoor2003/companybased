<x-app-layout :pageTitle="$entry->number">
    <x-slot name="header">
        <x-page-header :title="$entry->number" :description="$entry->entry_date->format('Y-m-d').($entry->reference ? ' · '.$entry->reference : '')" icon="document">
            <x-slot name="actions">
                @if ($entry->status === 'draft' && auth()->user()->can('accounting.journal_entries.edit'))
                    <x-button href="{{ route('accounting.journal.edit', $entry) }}" variant="secondary" icon="edit">Edit</x-button>
                @endif
                @if ($entry->status === 'draft' && auth()->user()->can('accounting.journal_entries.edit'))
                    <form method="POST" action="{{ route('accounting.journal.post', $entry) }}">
                        @csrf
                        @if ($entry->isBalanced())
                            <x-button type="submit" icon="check">Post entry</x-button>
                        @else
                            <x-button type="submit" icon="check" disabled>Post entry</x-button>
                        @endif
                    </form>
                @endif
                @if ($entry->status === 'posted' && auth()->user()->can('accounting.journal_entries.edit'))
                    <form method="POST" action="{{ route('accounting.journal.void', $entry) }}"
                        onsubmit="return confirm('Void entry {{ $entry->number }}?');">
                        @csrf
                        <x-button type="submit" variant="danger-secondary" icon="x">Void</x-button>
                    </form>
                @endif
                <x-button href="{{ route('accounting.journal.index') }}" variant="ghost" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    @include('accounting._tabs')

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat-card label="Status" :value="ucfirst($entry->status)" :icon="$entry->status === 'posted' ? 'check-circle' : 'clock'" :tone="$entry->status === 'posted' ? 'success' : ($entry->status === 'void' ? 'danger' : 'neutral')" />
        <x-stat-card label="Debits" :value="money($entry->totalDebits())" icon="arrow-down" tone="info" />
        <x-stat-card label="Credits" :value="money($entry->totalCredits())" icon="arrow-up" tone="warning" />
        <x-stat-card label="Balanced" :value="$entry->isBalanced() ? 'Yes' : 'No'" icon="check" :tone="$entry->isBalanced() ? 'success' : 'danger'" />
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-card title="Details" class="lg:col-span-1">
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between"><dt class="text-ink-faint">Number</dt><dd class="font-mono text-ink">{{ $entry->number }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-faint">Date</dt><dd class="text-ink">{{ $entry->entry_date->format('Y-m-d') }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-faint">Reference</dt><dd class="text-ink">{{ $entry->reference ?: '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-faint">Created by</dt><dd class="text-ink">{{ $entry->creator?->name ?: '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-faint">Posted at</dt><dd class="text-ink">{{ $entry->posted_at?->format('Y-m-d H:i') ?: '—' }}</dd></div>
                @if ($entry->description)
                    <div class="border-t border-line pt-3"><dt class="text-ink-faint">Description</dt><dd class="mt-1 text-ink-soft">{{ $entry->description }}</dd></div>
                @endif
            </dl>
        </x-card>

        <x-card title="Lines" :padding="false" class="lg:col-span-2">
            <div class="table-wrap !border-0 !rounded-none">
                <table class="table-base">
                    <thead>
                        <tr>
                            <th>Account</th>
                            <th>Memo</th>
                            <th class="text-right">Debit</th>
                            <th class="text-right">Credit</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($entry->items as $item)
                            <tr>
                                <td>
                                    <span class="font-medium text-ink">{{ $item->account?->name }}</span>
                                    <span class="block font-mono text-xs text-ink-faint">{{ $item->account?->code }}</span>
                                </td>
                                <td class="text-ink-soft">{{ $item->memo ?: '—' }}</td>
                                <td class="text-right text-ink">{{ $item->debit > 0 ? money($item->debit) : '—' }}</td>
                                <td class="text-right text-ink">{{ $item->credit > 0 ? money($item->credit) : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="2" class="text-right font-semibold text-ink">Totals</td>
                            <td class="text-right font-semibold text-ink">{{ money($entry->totalDebits()) }}</td>
                            <td class="text-right font-semibold text-ink">{{ money($entry->totalCredits()) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </x-card>
    </div>
</x-app-layout>