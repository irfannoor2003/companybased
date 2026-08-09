<x-app-layout :pageTitle="$account->code.' '.$account->name">
    <x-slot name="header">
        <x-page-header :title="$account->code.' '.$account->name" :description="'Account '.$account->id" icon="database">
            <x-slot name="actions">
                @if (auth()->user()->can('accounting.chart_of_accounts.edit'))
                    <x-button href="{{ route('accounting.accounts.edit', $account) }}" variant="secondary" icon="edit">Edit</x-button>
                @endif
                <x-button href="{{ route('accounting.accounts.index') }}" variant="ghost" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    @include('accounting._tabs')

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat-card label="Balance" :value="money($account->balance())" icon="database" tone="primary" />
        <x-stat-card label="Type" :value="\App\Models\Account::typeLabel($account->type)" icon="tag" tone="info" />
        <x-stat-card label="Parent" :value="$account->parent?->code ?: 'None'" icon="archive" tone="neutral" />
        <x-stat-card label="Child accounts" :value="$account->children->count()" icon="archive" tone="neutral" />
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-card title="Details" class="lg:col-span-1">
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between"><dt class="text-ink-faint">Code</dt><dd class="font-mono text-ink">{{ $account->code }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-faint">Name</dt><dd class="text-ink">{{ $account->name }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-faint">Sub type</dt><dd class="text-ink">{{ $account->sub_type ?: '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-faint">Currency</dt><dd class="text-ink">{{ $account->currency }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-faint">Status</dt><dd><x-accounting.status-badge :status="$account->is_active ? 'active' : 'inactive'" /></dd></div>
                @if ($account->description)
                    <div class="border-t border-line pt-3"><dt class="text-ink-faint">Description</dt><dd class="mt-1 text-ink-soft">{{ $account->description }}</dd></div>
                @endif
            </dl>
        </x-card>

        <x-card title="Posted journal activity" description="Posted lines on this account" :padding="false" class="lg:col-span-2">
            @if ($account->journalItems->isEmpty())
                <x-empty-state icon="document" title="No activity" description="No posted journal lines reference this account yet." />
            @else
                <div class="table-wrap !border-0 !rounded-none">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th>Entry</th>
                                <th>Date</th>
                                <th>Memo</th>
                                <th class="text-right">Debit</th>
                                <th class="text-right">Credit</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($account->journalItems as $item)
                                <tr>
                                    <td><span class="font-mono text-xs font-medium text-primary">{{ $item->entry?->number }}</span></td>
                                    <td class="text-ink-soft">{{ $item->entry?->entry_date?->format('Y-m-d') }}</td>
                                    <td class="text-ink-soft">{{ $item->memo ?: '—' }}</td>
                                    <td class="text-right text-ink">{{ $item->debit > 0 ? money($item->debit) : '—' }}</td>
                                    <td class="text-right text-ink">{{ $item->credit > 0 ? money($item->credit) : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-card>
    </div>
</x-app-layout>
