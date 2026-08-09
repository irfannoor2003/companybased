<x-app-layout :pageTitle="'Investment Portfolio'">
    <x-slot name="header">
        <x-page-header title="Portfolio" description="Company investment holdings and their current valuations." icon="investments">
            <x-slot name="actions">
                @if (auth()->user()->can('investments.portfolio.export'))
                    <x-export route="investments.portfolio.export" />
                @endif
                @if (auth()->user()->can('investments.portfolio.create'))
                    <x-button href="{{ route('investments.portfolio.create') }}" icon="plus">Add investment</x-button>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    @include('investments._tabs')

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-stat-card label="Holdings" :value="$stats['count']" icon="investments" tone="primary" />
        <x-stat-card label="Total invested" :value="money($stats['cost'])" icon="money" tone="info" />
        <x-stat-card label="Market value" :value="money($stats['value'])" icon="chart" tone="success" />
    </div>

    <div class="mt-6">
        <x-card :padding="false">
            <form method="GET" action="{{ route('investments.portfolio.index') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
                <div class="min-w-[220px] flex-1">
                    <x-input name="search" label="Search" placeholder="Code, name, institution…" leadingIcon="search"
                        value="{{ request('search') }}" size="sm" />
                </div>
                <div class="w-44">
                    <x-select name="type" label="Type" size="sm">
                        <option value="">All types</option>
                        @foreach ($types as $type)
                            <option value="{{ $type }}" @selected(request('type') === $type)>{{ ucfirst(str_replace('_', ' ', $type)) }}</option>
                        @endforeach
                    </x-select>
                </div>
                <div class="w-40">
                    <x-select name="status" label="Status" size="sm">
                        <option value="">Any status</option>
                        @foreach (\App\Models\Investment::statusOptions() as $status)
                            <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
                        @endforeach
                    </x-select>
                </div>
                <div class="flex gap-2">
                    <x-button type="submit" size="sm" icon="filter">Filter</x-button>
                    @if (request()->hasAny(['search', 'type', 'status']))
                        <x-button href="{{ route('investments.portfolio.index') }}" variant="ghost" size="sm">Clear</x-button>
                    @endif
                </div>
            </form>

            @if ($investments->isEmpty())
                <x-empty-state icon="investments" title="No investments" description="Add an investment to start the portfolio." />
            @else
                <div class="table-wrap !border-0 !rounded-none">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Name</th>
                                <th>Type</th>
                                <th>Institution</th>
                                <th class="text-right">Quantity</th>
                                <th class="text-right">Cost</th>
                                <th class="text-right">Value</th>
                                <th>Status</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($investments as $investment)
                                <tr>
                                    <td class="font-mono font-medium text-ink">{{ $investment->code }}</td>
                                    <td class="text-ink-soft">{{ $investment->name }}</td>
                                    <td><x-badge color="neutral">{{ ucfirst(str_replace('_', ' ', $investment->type)) }}</x-badge></td>
                                    <td class="text-ink-faint">{{ $investment->institution ?: '—' }}</td>
                                    <td class="text-right text-ink-soft">{{ number_format((float) $investment->quantity, 2) }}</td>
                                    <td class="text-right text-ink-soft">{{ money($investment->total_cost, $investment->currency) }}</td>
                                    <td class="text-right font-medium {{ $investment->gainLoss() >= 0 ? 'text-success' : 'text-danger' }}">{{ money($investment->marketValue(), $investment->currency) }}</td>
                                    <td>
                                        <x-badge :color="match ($investment->status) {
                                            'active' => 'success',
                                            'matured' => 'info',
                                            'sold' => 'danger',
                                            default => 'neutral',
                                        }">{{ ucfirst($investment->status) }}</x-badge>
                                    </td>
                                    <td class="text-right">
                                        @if (auth()->user()->can('investments.portfolio.edit'))
                                            <a href="{{ route('investments.portfolio.edit', $investment) }}" class="btn-ghost btn-icon btn-sm" title="Edit">
                                                <x-icon name="edit" class="size-4" />
                                            </a>
                                        @endif
                                        @if (auth()->user()->can('investments.portfolio.delete'))
                                            <form method="POST" action="{{ route('investments.portfolio.destroy', $investment) }}" class="inline" onsubmit="return confirm('Remove {{ $investment->code }} from portfolio?')">
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

            @if ($investments->hasPages())
                <div class="px-5 py-4">
                    {{ $investments->links() }}
                </div>
            @endif
        </x-card>
    </div>
</x-app-layout>