<x-app-layout :pageTitle="'Payroll'">
    <x-slot name="header">
        <x-page-header title="Payroll" description="Generate payslips from salary structures and attendance." icon="document">
            <x-slot name="actions">
                @if (auth()->user()->can('employees.payroll_runs.create'))
                    <x-button href="{{ route('employees.payroll.create') }}" icon="plus">New payroll run</x-button>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    @include('employees._tabs')

    <div class="mt-6">
        <x-card :padding="false">
            <form method="GET" action="{{ route('employees.payroll.index') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
                <div class="min-w-[220px] flex-1">
                    <x-input name="search" label="Search" placeholder="Run number…" leadingIcon="search"
                        value="{{ request('search') }}" size="sm" />
                </div>
                <div class="w-40">
                    <x-select name="status" label="Status" size="sm">
                        <option value="">Any status</option>
                        @foreach (\App\Models\PayrollRun::statusOptions() as $status)
                            <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
                        @endforeach
                    </x-select>
                </div>
                <div class="flex gap-2">
                    <x-button type="submit" size="sm" icon="filter">Filter</x-button>
                    @if (request()->hasAny(['search', 'status']))
                        <x-button href="{{ route('employees.payroll.index') }}" variant="ghost" size="sm">Clear</x-button>
                    @endif
                </div>
            </form>

            @if ($runs->isEmpty())
                <x-empty-state icon="document" title="No payroll runs" description="Create a payroll run to generate payslips." />
            @else
                <div class="table-wrap !border-0 !rounded-none">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th>Number</th>
                                <th>Period</th>
                                <th class="text-right">Payslips</th>
                                <th class="text-right">Gross</th>
                                <th class="text-right">Net</th>
                                <th>Status</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($runs as $run)
                                <tr>
                                    <td>
                                        <a href="{{ route('employees.payroll.show', $run) }}" class="font-mono font-medium text-ink hover:text-primary">
                                            {{ $run->number }}
                                        </a>
                                    </td>
                                    <td class="text-ink-soft">{{ $run->period_start->format('M d') }} – {{ $run->period_end->format('M d, Y') }}</td>
                                    <td class="text-right text-ink-soft">{{ $run->payslips_count }}</td>
                                    <td class="text-right text-ink">{{ money($run->total_gross, $run->currency) }}</td>
                                    <td class="text-right font-medium text-ink">{{ money($run->total_net, $run->currency) }}</td>
                                    <td><x-employees.status-badge :status="$run->status" /></td>
                                    <td class="text-right">
                                        <a href="{{ route('employees.payroll.show', $run) }}" class="btn-ghost btn-icon btn-sm" title="View">
                                            <x-icon name="eye" class="size-4" />
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($runs->hasPages())
                <div class="px-5 py-4">
                    {{ $runs->links() }}
                </div>
            @endif
        </x-card>
    </div>
</x-app-layout>