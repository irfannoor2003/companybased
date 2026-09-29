<x-app-layout page-title="Grand Report">
    <x-slot name="header">
        <x-page-header title="Grand Report" description="A consolidated operating view of the entire ERP for the selected period." icon="chart">
            <x-slot name="actions"><x-button href="{{ route('reports.index') }}" variant="secondary" icon="arrow-left">All reports</x-button></x-slot>
        </x-page-header>
    </x-slot>

    <div class="space-y-6">
        <x-card>
            <form method="GET" action="{{ route('reports.grand') }}" class="flex flex-col gap-4 sm:flex-row sm:items-end">
                <div class="flex-1">
                    <x-input-label for="period" value="Report period" />
                    <select id="period" name="period" class="select-input mt-1">
                        <option value="daily" @selected(old('period', $period) === 'daily')>Daily</option>
                        <option value="weekly" @selected(old('period', $period) === 'weekly')>Weekly</option>
                        <option value="monthly" @selected(old('period', $period) === 'monthly')>Monthly</option>
                        <option value="yearly" @selected(old('period', $period) === 'yearly')>Yearly</option>
                    </select>
                </div>
                <div class="flex-1">
                    <x-input-label for="date" value="Period date" />
                    <x-text-input id="date" name="date" type="date" class="mt-1" :value="$anchor->format('Y-m-d')" />
                </div>
                <x-button type="submit" icon="refresh">Refresh report</x-button>
            </form>
        </x-card>

        <div class="flex flex-wrap items-center gap-2 rounded-xl border border-primary/15 bg-primary/5 px-4 py-3 text-sm text-ink-soft">
            <x-icon name="calendar" class="size-4 text-primary" />
            Showing <strong class="text-ink">{{ $from->format('d M Y') }}</strong> to <strong class="text-ink">{{ $to->format('d M Y') }}</strong>
            <span class="text-ink-faint">·</span>
            <span>{{ ucfirst($period) }} period</span>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-stat-card label="Sales revenue" :value="money($summary['sales_revenue'])" icon="sales" tone="primary" hint="{{ $summary['sales_orders'] }} invoices" />
            <x-stat-card label="Cash collected" :value="money($summary['sales_collected'])" icon="money" tone="success" hint="From invoiced sales" />
            <x-stat-card label="Purchase value" :value="money($summary['purchase_value'])" icon="suppliers" tone="info" hint="{{ $summary['purchase_orders'] }} purchase orders" />
            <x-stat-card label="Net cash movement" :value="money($summary['cash_in'] - $summary['cash_out'])" icon="banking" :tone="$summary['cash_in'] >= $summary['cash_out'] ? 'success' : 'danger'" hint="Cash in minus cash out" />
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <x-card title="Revenue trend" description="Invoiced revenue across the selected reporting window.">
                <x-base-chart type="line" :data="json_encode(['labels' => $chart['labels'], 'datasets' => [['label' => 'Revenue', 'data' => $chart['revenue'], 'borderColor' => '#4f46e5', 'backgroundColor' => 'rgba(79, 70, 229, 0.12)', 'fill' => true, 'tension' => 0.4]]])" :options="json_encode(['responsive' => true, 'maintainAspectRatio' => false, 'plugins' => ['legend' => ['position' => 'bottom']], 'scales' => ['y' => ['beginAtZero' => true]]])" height="280" />
            </x-card>
            <x-card title="Cash movement" description="Deposits and withdrawals across the reporting window.">
                <x-base-chart type="line" :data="json_encode(['labels' => $chart['labels'], 'datasets' => [['label' => 'Cash in', 'data' => $chart['cashIn'], 'borderColor' => '#10b981', 'backgroundColor' => 'rgba(16, 185, 129, 0.12)', 'fill' => true, 'tension' => 0.4], ['label' => 'Cash out', 'data' => $chart['cashOut'], 'borderColor' => '#f59e0b', 'backgroundColor' => 'rgba(245, 158, 11, 0.12)', 'fill' => true, 'tension' => 0.4]]])" :options="json_encode(['responsive' => true, 'maintainAspectRatio' => false, 'plugins' => ['legend' => ['position' => 'bottom']], 'scales' => ['y' => ['beginAtZero' => true]]])" height="280" />
            </x-card>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <x-card title="Commercial activity" description="The main value movements in this period.">
                <x-base-chart type="bar" :data="json_encode(['labels' => array_column($activity, 'label'), 'datasets' => [['label' => 'Value', 'data' => array_column($activity, 'value'), 'backgroundColor' => ['#4f46e5', '#0ea5e9', '#10b981', '#10b981', '#f59e0b'], 'borderRadius' => 6]]])" :options="json_encode(['responsive' => true, 'maintainAspectRatio' => false, 'plugins' => ['legend' => ['display' => false]], 'scales' => ['y' => ['beginAtZero' => true]]])" height="280" />
            </x-card>
            <x-card title="Operational health" description="Important counts that need management attention.">
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                    <div class="rounded-xl bg-surface-muted/50 p-4"><p class="text-xl font-bold text-ink">{{ number_format($lowStock) }}</p><p class="mt-1 text-xs text-ink-soft">Low stock items</p></div>
                    <div class="rounded-xl bg-surface-muted/50 p-4"><p class="text-xl font-bold text-ink">{{ number_format($summary['posted_journals']) }}</p><p class="mt-1 text-xs text-ink-soft">Posted journals</p></div>
                    <div class="rounded-xl bg-surface-muted/50 p-4"><p class="text-xl font-bold text-ink">{{ number_format($summary['leave_requests']) }}</p><p class="mt-1 text-xs text-ink-soft">Leave requests</p></div>
                    <div class="rounded-xl bg-surface-muted/50 p-4"><p class="text-xl font-bold text-ink">{{ number_format($summary['visits']) }}</p><p class="mt-1 text-xs text-ink-soft">Customer visits</p></div>
                    <div class="rounded-xl bg-surface-muted/50 p-4"><p class="text-xl font-bold text-ink">{{ number_format($summary['stock_movements']) }}</p><p class="mt-1 text-xs text-ink-soft">Stock movements</p></div>
                    <div class="rounded-xl bg-surface-muted/50 p-4"><p class="text-xl font-bold text-ink">{{ number_format($summary['payroll_runs']) }}</p><p class="mt-1 text-xs text-ink-soft">Payroll runs</p></div>
                </div>
            </x-card>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <x-card title="Sales & customers" description="Commercial performance snapshot.">
                <dl class="divide-y divide-line">
                    <div class="flex items-center justify-between py-3"><dt class="text-sm text-ink-soft">Active customers</dt><dd class="font-semibold text-ink">{{ number_format($summary['customers']) }}</dd></div>
                    <div class="flex items-center justify-between py-3"><dt class="text-sm text-ink-soft">Invoices</dt><dd class="font-semibold text-ink">{{ number_format($summary['sales_orders']) }}</dd></div>
                    <div class="flex items-center justify-between py-3"><dt class="text-sm text-ink-soft">Revenue</dt><dd class="font-semibold text-primary">{{ money($summary['sales_revenue']) }}</dd></div>
                    <div class="flex items-center justify-between py-3"><dt class="text-sm text-ink-soft">Collected</dt><dd class="font-semibold text-emerald-600">{{ money($summary['sales_collected']) }}</dd></div>
                </dl>
            </x-card>
            <x-card title="People & operations" description="Workforce activity snapshot.">
                <dl class="divide-y divide-line">
                    <div class="flex items-center justify-between py-3"><dt class="text-sm text-ink-soft">Active employees</dt><dd class="font-semibold text-ink">{{ number_format($summary['employees']) }}</dd></div>
                    <div class="flex items-center justify-between py-3"><dt class="text-sm text-ink-soft">Attendance records</dt><dd class="font-semibold text-ink">{{ number_format($summary['attendance_records']) }}</dd></div>
                    <div class="flex items-center justify-between py-3"><dt class="text-sm text-ink-soft">POS revenue</dt><dd class="font-semibold text-primary">{{ money($summary['pos_revenue']) }}</dd></div>
                    <div class="flex items-center justify-between py-3"><dt class="text-sm text-ink-soft">Customer visits</dt><dd class="font-semibold text-ink">{{ number_format($summary['visits']) }}</dd></div>
                </dl>
            </x-card>
            <x-card title="Assets & capital" description="Long-term business position.">
                <dl class="divide-y divide-line">
                    <div class="flex items-center justify-between py-3"><dt class="text-sm text-ink-soft">Assets added</dt><dd class="font-semibold text-ink">{{ number_format($summary['assets_added']) }}</dd></div>
                    <div class="flex items-center justify-between py-3"><dt class="text-sm text-ink-soft">Investments added</dt><dd class="font-semibold text-ink">{{ number_format($summary['investments_added']) }}</dd></div>
                    <div class="flex items-center justify-between py-3"><dt class="text-sm text-ink-soft">Capital contributed</dt><dd class="font-semibold text-primary">{{ money($summary['capital_added']) }}</dd></div>
                    <div class="flex items-center justify-between py-3"><dt class="text-sm text-ink-soft">Low stock alerts</dt><dd class="font-semibold {{ $summary['low_stock'] ? 'text-amber-600' : 'text-emerald-600' }}">{{ number_format($summary['low_stock']) }}</dd></div>
                </dl>
            </x-card>
        </div>
    </div>
</x-app-layout>
