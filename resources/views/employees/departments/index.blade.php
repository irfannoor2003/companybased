<x-app-layout :pageTitle="'Departments'">
    <x-slot name="header">
        <x-page-header title="Departments" description="Organise the workforce into departments with heads of department." icon="building">
            <x-slot name="actions">
                @if (auth()->user()->can('employees.departments.export'))
                    <x-export route="employees.departments.export" />
                @endif
                @if (auth()->user()->can('employees.departments.create'))
                    <x-button href="{{ route('employees.departments.create') }}" icon="plus">New department</x-button>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    @include('employees._tabs')

    <div class="mt-6">
        <x-card :padding="false">
            <form method="GET" action="{{ route('employees.departments.index') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
                <div class="min-w-[220px] flex-1">
                    <x-input name="search" label="Search" placeholder="Name or code…" leadingIcon="search"
                        value="{{ request('search') }}" size="sm" />
                </div>
                <div class="w-40">
                    <x-select name="status" label="Status" size="sm">
                        <option value="">Any status</option>
                        <option value="active" @selected(request('status') === 'active')>Active</option>
                        <option value="inactive" @selected(request('status') === 'inactive')>Inactive</option>
                    </x-select>
                </div>
                <div class="flex gap-2">
                    <x-button type="submit" size="sm" icon="filter">Filter</x-button>
                    @if (request()->hasAny(['search', 'status']))
                        <x-button href="{{ route('employees.departments.index') }}" variant="ghost" size="sm">Clear</x-button>
                    @endif
                </div>
            </form>

            @if ($departments->isEmpty())
                <x-empty-state icon="building" title="No departments" description="Create a department to start organising employees." />
            @else
                <div class="table-wrap !border-0 !rounded-none">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Code</th>
                                <th>Head of department</th>
                                <th class="text-right">Employees</th>
                                <th>Status</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($departments as $department)
                                <tr>
                                    <td>
                                        <a href="{{ route('employees.departments.show', $department) }}" class="font-medium text-ink hover:text-primary">
                                            {{ $department->name }}
                                        </a>
                                    </td>
                                    <td class="font-mono text-ink-soft">{{ $department->code ?: '—' }}</td>
                                    <td class="text-ink-soft">{{ $department->headOfDepartment?->fullName() ?: '—' }}</td>
                                    <td class="text-right text-ink-soft">{{ $department->employees_count }}</td>
                                    <td>
                                        <x-badge :color="$department->is_active ? 'success' : 'neutral'">{{ $department->is_active ? 'Active' : 'Inactive' }}</x-badge>
                                    </td>
                                    <td class="text-right">
                                        <div class="inline-flex gap-1">
                                            <a href="{{ route('employees.departments.show', $department) }}" class="btn-ghost btn-icon btn-sm" title="View">
                                                <x-icon name="eye" class="size-4" />
                                            </a>
                                            @if (auth()->user()->can('employees.departments.edit'))
                                                <a href="{{ route('employees.departments.edit', $department) }}" class="btn-ghost btn-icon btn-sm" title="Edit">
                                                    <x-icon name="tag" class="size-4" />
                                                </a>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($departments->hasPages())
                <div class="px-5 py-4">
                    {{ $departments->links() }}
                </div>
            @endif
        </x-card>
    </div>
</x-app-layout>
