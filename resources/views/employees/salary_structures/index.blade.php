<x-app-layout :pageTitle="'Salary Structures'">
    <x-slot name="header">
        <x-page-header title="Salary Structures" description="Configure basic salary and allowances per employee." icon="money">
            <x-slot name="actions">
                @if (auth()->user()->can('employees.salary_structures.export'))
                    <x-export route="employees.salary_structures.export" />
                @endif
                @if (auth()->user()->can('employees.salary_structures.create'))
                    <x-button href="{{ route('employees.salary_structures.create') }}" icon="plus">New structure</x-button>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    @include('employees._tabs')

    <div class="mt-6">
        <x-card :padding="false">
            <form method="GET" action="{{ route('employees.salary_structures.index') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
                <div class="min-w-[220px] flex-1">
                    <x-select name="employee_id" label="Employee" size="sm">
                        <option value="">All employees</option>
                        @foreach ($employees as $employee)
                            <option value="{{ $employee->id }}" @selected(request('employee_id') == $employee->id)>{{ $employee->fullName() }}</option>
                        @endforeach
                    </x-select>
                </div>
                <div class="w-40">
                    <x-select name="active" label="Active only" size="sm">
                        <option value="">All</option>
                        <option value="1" @selected(request('active') === '1')>Active</option>
                    </x-select>
                </div>
                <div class="flex gap-2">
                    <x-button type="submit" size="sm" icon="filter">Filter</x-button>
                    @if (request()->hasAny(['employee_id', 'active']))
                        <x-button href="{{ route('employees.salary_structures.index') }}" variant="ghost" size="sm">Clear</x-button>
                    @endif
                </div>
            </form>

            @if ($structures->isEmpty())
                <x-empty-state icon="money" title="No salary structures" description="Create a salary structure to start paying employees." />
            @else
                <div class="table-wrap !border-0 !rounded-none">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Effective from</th>
                                <th class="text-right">Basic</th>
                                <th class="text-right">Allowances</th>
                                <th class="text-right">Gross</th>
                                <th>Status</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($structures as $structure)
                                <tr>
                                    <td class="font-medium text-ink">{{ $structure->employee?->fullName() ?: '—' }}</td>
                                    <td class="text-ink-soft">{{ $structure->effective_from->format('Y-m-d') }}</td>
                                    <td class="text-right text-ink">{{ money($structure->basic_salary) }}</td>
                                    <td class="text-right text-ink-soft">{{ money($structure->allowances()) }}</td>
                                    <td class="text-right font-medium text-ink">{{ money($structure->gross()) }}</td>
                                    <td><x-badge :color="$structure->is_active ? 'success' : 'neutral'">{{ $structure->is_active ? 'Active' : 'Inactive' }}</x-badge></td>
                                    <td class="text-right">
                                        @if (auth()->user()->can('employees.salary_structures.edit'))
                                            <a href="{{ route('employees.salary_structures.edit', $structure) }}" class="btn-ghost btn-icon btn-sm" title="Edit">
                                                <x-icon name="tag" class="size-4" />
                                            </a>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($structures->hasPages())
                <div class="px-5 py-4">
                    {{ $structures->links() }}
                </div>
            @endif
        </x-card>
    </div>
</x-app-layout>