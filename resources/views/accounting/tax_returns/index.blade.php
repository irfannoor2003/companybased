<x-app-layout :pageTitle="'Tax returns'">
    <x-slot name="header">
        <x-page-header title="Tax returns" description="Period tax filings — sales, income, withholding and other returns." icon="tax">
            <x-slot name="actions">
                @if (auth()->user()->can('accounting.tax_returns.export'))
                    <x-export route="accounting.tax_returns.export" />
                @endif
                @if (auth()->user()->can('accounting.tax_returns.create'))
                    <x-button href="{{ route('accounting.tax_returns.create') }}" icon="plus">New return</x-button>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    @include('accounting._tabs')

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-stat-card label="Unpaid tax due" :value="money($totalDue)" icon="warning" tone="warning" hint="Draft and filed returns" />
        <x-stat-card label="Paid" :value="money($totalPaid)" icon="check" tone="success" hint="Returns marked paid" />
        <x-stat-card label="Returns shown" :value="$returns->total()" icon="document" tone="neutral" />
    </div>

    <div class="mt-6">
        <x-card :padding="false">
            <form method="GET" action="{{ route('accounting.tax_returns.index') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
                <div class="min-w-[220px] flex-1">
                    <x-input name="search" label="Search" placeholder="Number, period…" leadingIcon="search"
                        value="{{ request('search') }}" size="sm" />
                </div>
                <div class="w-40">
                    <x-select name="type" label="Type" size="sm">
                        <option value="">Any type</option>
                        @foreach (\App\Models\TaxReturn::typeOptions() as $type)
                            <option value="{{ $type }}" @selected(request('type') === $type)>{{ ucfirst($type) }}</option>
                        @endforeach
                    </x-select>
                </div>
                <div class="w-36">
                    <x-select name="status" label="Status" size="sm">
                        <option value="">Any status</option>
                        @foreach (\App\Models\TaxReturn::statusOptions() as $status)
                            <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
                        @endforeach
                    </x-select>
                </div>
                <div class="flex gap-2">
                    <x-button type="submit" size="sm" icon="filter">Filter</x-button>
                    @if (request()->hasAny(['search', 'type', 'status']))
                        <x-button href="{{ route('accounting.tax_returns.index') }}" variant="ghost" size="sm">Clear</x-button>
                    @endif
                </div>
            </form>

            @if ($returns->isEmpty())
                <x-empty-state icon="tax" title="No tax returns" description="Create a return for the period to track filings and payments." />
            @else
                <div class="table-wrap !border-0 !rounded-none">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th>Number</th>
                                <th>Type</th>
                                <th>Period</th>
                                <th class="text-right">Taxable</th>
                                <th class="text-right">Due</th>
                                <th>Status</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($returns as $return)
                                <tr>
                                    <td>
                                        <a href="{{ route('accounting.tax_returns.show', $return) }}" class="font-mono text-xs font-medium text-primary hover:underline">
                                            {{ $return->number }}
                                        </a>
                                    </td>
                                    <td><span class="text-ink-soft">{{ ucfirst($return->tax_type) }}</span></td>
                                    <td class="text-ink-soft">{{ $return->period_label }}</td>
                                    <td class="text-right text-ink">{{ money($return->taxable_amount, $return->currency) }}</td>
                                    <td class="text-right font-medium text-ink">{{ money($return->tax_due, $return->currency) }}</td>
                                    <td><x-accounting.status-badge :status="$return->status" /></td>
                                    <td class="text-right">
                                        <a href="{{ route('accounting.tax_returns.show', $return) }}" class="btn-ghost btn-icon btn-sm" title="View">
                                            <x-icon name="eye" class="size-4" />
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($returns->hasPages())
                <div class="px-5 py-4">
                    {{ $returns->links() }}
                </div>
            @endif
        </x-card>
    </div>
</x-app-layout>