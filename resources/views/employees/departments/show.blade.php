<x-app-layout :pageTitle="$department->name">
    <x-slot name="header">
        <x-page-header :title="$department->name" :description="$department->code ? 'Code '.$department->code : null" icon="building">
            <x-slot name="actions">
                @if (auth()->user()->can('employees.departments.edit'))
                    <x-button href="{{ route('employees.departments.edit', $department) }}" variant="secondary" icon="tag">Edit</x-button>
                @endif
                <x-button href="{{ route('employees.departments.index') }}" variant="ghost" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    @include('employees._tabs')

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-card title="Department details" class="lg:col-span-2">
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between"><dt class="text-ink-faint">Name</dt><dd class="text-ink">{{ $department->name }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-faint">Code</dt><dd class="font-mono text-ink">{{ $department->code ?: '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-faint">Head of department</dt><dd class="text-ink">{{ $department->headOfDepartment?->fullName() ?: '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-faint">Employees</dt><dd class="text-ink">{{ $department->employees->count() }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-faint">Status</dt><dd><x-badge :color="$department->is_active ? 'success' : 'neutral'">{{ $department->is_active ? 'Active' : 'Inactive' }}</x-badge></dd></div>
                @if ($department->description)
                    <div class="border-t border-line pt-3"><dt class="text-ink-faint">Description</dt><dd class="mt-1 text-ink-soft">{{ $department->description }}</dd></div>
                @endif
            </dl>
        </x-card>

        <x-card title="Employees" :padding="false">
            @if ($department->employees->isEmpty())
                <p class="px-5 py-6 text-sm text-ink-faint">No employees in this department yet.</p>
            @else
                <ul class="divide-y divide-line">
                    @foreach ($department->employees->sortBy('first_name') as $employee)
                        <li>
                            <a href="{{ route('employees.employees.show', $employee) }}" class="flex items-center justify-between gap-2 px-5 py-3 hover:bg-surface-muted/60">
                                <span class="text-sm font-medium text-ink">{{ $employee->fullName() }}</span>
                                <span class="font-mono text-xs text-ink-faint">{{ $employee->employee_code }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-card>
    </div>
</x-app-layout>
