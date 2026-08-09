@php
    $tabs = [
        ['label' => 'Chart of accounts', 'route' => 'accounting.accounts.index', 'icon' => 'database'],
        ['label' => 'Journal', 'route' => 'accounting.journal.index', 'icon' => 'document'],
        ['label' => 'Expense claims', 'route' => 'accounting.expense_claims.index', 'icon' => 'money'],
        ['label' => 'Bills', 'route' => 'accounting.bills.index', 'icon' => 'invoice'],
        ['label' => 'Tax returns', 'route' => 'accounting.tax_returns.index', 'icon' => 'tax'],
        ['label' => 'Budgets', 'route' => 'accounting.budgets.index', 'icon' => 'chart'],
    ];
@endphp

<div class="mt-6 border-b border-line">
    <nav class="flex flex-wrap gap-1">
        @foreach ($tabs as $tab)
            @php
                $active = request()->routeIs($tab['route']) || request()->routeIs(str_replace('.index', '.', $tab['route']).'*');
            @endphp
            <a href="{{ route($tab['route']) }}"
                class="inline-flex items-center gap-2 rounded-t-lg px-4 py-2.5 text-sm font-medium transition {{ $active ? 'border-b-2 border-primary bg-primary/5 text-primary' : 'text-ink-soft hover:bg-surface-muted/60 hover:text-ink' }}">
                <x-icon :name="$tab['icon']" class="size-4" />
                {{ $tab['label'] }}
            </a>
        @endforeach
    </nav>
</div>
