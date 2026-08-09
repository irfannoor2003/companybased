<x-app-layout :pageTitle="'Capital Drawings'">
    <x-slot name="header">
        <x-page-header title="Drawings" description="Owner withdrawals and capital distributions from the business." icon="arrow-right">
            <x-slot name="actions">
                @if (auth()->user()->can('capital_accounts.drawings.export'))
                    <x-export route="capital.drawings.export" />
                @endif
                @if (auth()->user()->can('capital_accounts.drawings.create'))
                    <x-button href="{{ route('capital.drawings.create') }}" icon="plus">Record drawing</x-button>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    @include('capital._tabs')

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-stat-card label="Total drawings" :value="money($total)" icon="arrow-right" tone="danger" />
        <x-stat-card label="Transactions" :value="$drawings->total()" icon="document" tone="primary" />
    </div>

    <div class="mt-6">
        <x-card :padding="false">
            <form method="GET" action="{{ route('capital.drawings.index') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
                <div class="min-w-[220px] flex-1">
                    <x-input name="search" label="Search" placeholder="Reference, recipient, notes…" leadingIcon="search"
                        value="{{ request('search') }}" size="sm" />
                </div>
                <div class="w-48">
                    <x-select name="recipient" label="Recipient" size="sm">
                        <option value="">All recipients</option>
                        @foreach ($recipients as $recipient)
                            <option value="{{ $recipient }}" @selected(request('recipient') === $recipient)>{{ $recipient }}</option>
                        @endforeach
                    </x-select>
                </div>
                <div class="w-40">
                    <x-input name="from" label="From" type="date" value="{{ request('from') }}" size="sm" />
                </div>
                <div class="w-40">
                    <x-input name="to" label="To" type="date" value="{{ request('to') }}" size="sm" />
                </div>
                <div class="flex gap-2">
                    <x-button type="submit" size="sm" icon="filter">Filter</x-button>
                    @if (request()->hasAny(['search', 'recipient', 'from', 'to']))
                        <x-button href="{{ route('capital.drawings.index') }}" variant="ghost" size="sm">Clear</x-button>
                    @endif
                </div>
            </form>

            @if ($drawings->isEmpty())
                <x-empty-state icon="arrow-right" title="No drawings" description="Record an owner drawing to get started." />
            @else
                <div class="table-wrap !border-0 !rounded-none">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Date</th>
                                <th>Recipient</th>
                                <th>Method</th>
                                <th class="text-right">Amount</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($drawings as $drawing)
                                <tr>
                                    <td class="font-mono font-medium text-ink">{{ $drawing->reference }}</td>
                                    <td class="text-ink-soft">{{ $drawing->drawing_date?->format('Y-m-d') }}</td>
                                    <td class="text-ink-soft">{{ $drawing->recipient }}</td>
                                    <td class="text-ink-faint">{{ $drawing->method ? ucfirst(str_replace('_', ' ', $drawing->method)) : '—' }}</td>
                                    <td class="text-right font-medium text-danger">{{ money($drawing->amount, $drawing->currency) }}</td>
                                    <td class="text-right">
                                        @if (auth()->user()->can('capital_accounts.drawings.edit'))
                                            <a href="{{ route('capital.drawings.edit', $drawing) }}" class="btn-ghost btn-icon btn-sm" title="Edit">
                                                <x-icon name="edit" class="size-4" />
                                            </a>
                                        @endif
                                        @if (auth()->user()->can('capital_accounts.drawings.delete'))
                                            <form method="POST" action="{{ route('capital.drawings.destroy', $drawing) }}" class="inline" onsubmit="return confirm('Delete drawing {{ $drawing->reference }}?')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn-ghost btn-icon btn-sm text-danger" title="Delete">
                                                    <x-icon name="trash" class="size-4" />
                                                </button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($drawings->hasPages())
                <div class="px-5 py-4">
                    {{ $drawings->links() }}
                </div>
            @endif
        </x-card>
    </div>
</x-app-layout>