<x-app-layout :pageTitle="$run->number">
    <x-slot name="header">
        <x-page-header :title="$run->number" :description="$run->period_start->format('M d Y').' – '.$run->period_end->format('M d Y').' · '.($run->preparer?->name ?: '—')" icon="document">
            <x-slot name="actions">
                @if (auth()->user()->can('employees.payroll_runs.export') && $run->payslips->isNotEmpty())
                    <x-button href="{{ route('employees.payroll.export', array_merge(['run' => $run->id], ['format' => 'csv'])) }}" variant="secondary" icon="download">CSV</x-button>
                    <x-button href="{{ route('employees.payroll.export', array_merge(['run' => $run->id], ['format' => 'json'])) }}" variant="secondary" icon="download">JSON</x-button>
                @endif
                <x-button href="{{ route('employees.payroll.index') }}" variant="ghost" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    @include('employees._tabs')

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-4">
        <x-stat-card label="Total gross" :value="money($run->total_gross, $run->currency)" icon="money" tone="primary" />
        <x-stat-card label="Total deductions" :value="money($run->total_deductions, $run->currency)" icon="x" tone="danger" />
        <x-stat-card label="Total net" :value="money($run->total_net, $run->currency)" icon="check" tone="success" />
        <x-stat-card label="Payslips" :value="$run->payslips->count()" icon="document" tone="info" />
    </div>

    @if ($run->status !== 'void')
        <div class="mt-6 flex flex-wrap items-center gap-2">
            <x-badge :color="$run->status === 'paid' ? 'success' : ($run->status === 'void' ? 'danger' : 'warning')" dot>{{ ucfirst($run->status) }}</x-badge>
            @if (auth()->user()->can('employees.payroll_runs.approve') && $run->status !== 'paid')
                <form method="POST" action="{{ route('employees.payroll.mark-paid', $run) }}">
                    @csrf
                    <x-button type="submit" variant="success" size="sm" icon="check">Mark as paid</x-button>
                </form>
            @endif
            @if (auth()->user()->can('employees.payroll_runs.approve') && $run->status === 'draft')
                <form method="POST" action="{{ route('employees.payroll.generate', $run) }}">
                    @csrf
                    <x-button type="submit" variant="secondary" size="sm" icon="zap">Regenerate payslips</x-button>
                </form>
            @endif
            @if (auth()->user()->can('employees.payroll_runs.delete') && $run->status !== 'paid')
                <form method="POST" action="{{ route('employees.payroll.destroy', $run) }}" onsubmit="return confirm('Delete this payroll run?')">
                    @csrf
                    @method('DELETE')
                    <x-button type="submit" variant="danger-secondary" size="sm" icon="trash">Delete</x-button>
                </form>
            @endif
        </div>
    @endif

    @if ($run->notes)
        <div class="mt-6">
            <x-card title="Notes">
                <p class="text-sm text-ink-soft">{{ $run->notes }}</p>
            </x-card>
        </div>
    @endif

    <div class="mt-6">
        <x-card title="Payslips" :padding="false">
            @if ($run->payslips->isEmpty())
                <p class="px-5 py-6 text-sm text-ink-faint">No payslips generated yet. Add an active salary structure and regenerate.</p>
            @else
                <div class="table-wrap !border-0 !rounded-none">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Department</th>
                                <th class="text-right">Gross</th>
                                <th class="text-right">Attendance deductions</th>
                                <th class="text-right">Net</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($run->payslips as $payslip)
                                <tr>
                                    <td class="font-medium text-ink">{{ $payslip->employee?->fullName() ?: '—' }}</td>
                                    <td class="text-ink-soft">{{ $payslip->employee?->department?->name ?: '—' }}</td>
                                    <td class="text-right text-ink">{{ money($payslip->gross_pay, $run->currency) }}</td>
                                    <td class="text-right text-rose-600">{{ money($payslip->attendance_deductions, $run->currency) }}</td>
                                    <td class="text-right font-medium text-ink">{{ money($payslip->net_pay, $run->currency) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-card>
    </div>
</x-app-layout>