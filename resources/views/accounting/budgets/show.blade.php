<x-app-layout :pageTitle="$budget->name">
    <x-slot name="header">
        <x-page-header :title="$budget->name" :description="'FY '.$budget->fiscal_year" icon="chart">
            <x-slot name="actions">
                @if (auth()->user()->can('accounting.budgeting.edit') && $budget->status !== 'closed')
                    <x-button href="{{ route('accounting.budgets.edit', $budget) }}" variant="secondary" icon="edit">Edit</x-button>
                @endif
                @if (auth()->user()->can('accounting.budgeting.edit') && $budget->status !== 'closed')
                    <form method="POST" action="{{ route('accounting.budgets.status', $budget) }}">
                        @csrf
                        <input type="hidden" name="status" value="active">
                        @if ($budget->status === 'active')
                            <x-button type="submit" variant="success" icon="check" disabled>Activate</x-button>
                        @else
                            <x-button type="submit" variant="success" icon="check">Activate</x-button>
                        @endif
                    </form>
                @endif
                <x-button href="{{ route('accounting.budgets.index') }}" variant="ghost" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    @include('accounting._tabs')

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat-card label="Budgeted" :value="money($budget->totalBudgeted(), $budget->currency)" icon="chart" tone="primary" />
        <x-stat-card label="Actual" :value="money($budget->items->sum(fn ($i) => $i->actualAmount()), $budget->currency)" icon="activity" tone="info" />
        @php
            $variance = $budget->items->sum(fn ($i) => $i->actualAmount() - (float) $i->budget_amount);
        @endphp
        <x-stat-card label="Variance" :value="money($variance, $budget->currency)" icon="arrow-up" :tone="$variance <= 0 ? 'success' : 'warning'" />
        <x-stat-card label="Status" :value="ucfirst($budget->status)" icon="tag" :tone="$budget->status === 'active' ? 'success' : 'neutral'" />
    </div>

    <div class="mt-6">
        <x-card title="Budget lines" description="Budget vs actual for the fiscal year" :padding="false">
            @if ($budget->items->isEmpty())
                <x-empty-state icon="chart" title="No lines" description="This budget has no account lines." />
            @else
                <div class="table-wrap !border-0 !rounded-none">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th>Account</th>
                                <th>Type</th>
                                <th class="text-right">Budgeted</th>
                                <th class="text-right">Actual</th>
                                <th class="text-right">Variance</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($budget->items as $item)
                                @php
                                    $actual = $item->actualAmount();
                                    $lineVariance = round($actual - (float) $item->budget_amount, 2);
                                    $overBudget = $item->account?->type === 'expense' ? $lineVariance > 0 : $lineVariance < 0;
                                @endphp
                                <tr>
                                    <td>
                                        <span class="font-medium text-ink">{{ $item->account?->name }}</span>
                                        <span class="block font-mono text-xs text-ink-faint">{{ $item->account?->code }}</span>
                                    </td>
                                    <td><span class="text-ink-soft">{{ $item->account ? \App\Models\Account::typeLabel($item->account->type) : '—' }}</span></td>
                                    <td class="text-right text-ink">{{ money($item->budget_amount, $budget->currency) }}</td>
                                    <td class="text-right text-ink">{{ money($actual, $budget->currency) }}</td>
                                    <td class="text-right font-medium {{ $overBudget ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400' }}">
                                        {{ $lineVariance >= 0 ? '+' : '−' }}{{ money(abs($lineVariance), $budget->currency) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-card>
    </div>
</x-app-layout>