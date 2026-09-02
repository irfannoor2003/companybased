@php
    $tabs = [
        ['label' => 'Employees', 'route' => 'employees.employees.index', 'icon' => 'employees', 'permission' => 'employees.employees.view'],
        ['label' => 'Departments', 'route' => 'employees.departments.index', 'icon' => 'building', 'permission' => 'employees.departments.view'],
        ['label' => 'Attendance', 'route' => 'employees.attendance.index', 'icon' => 'clock', 'permission' => 'employees.attendance.view'],
        ['label' => 'Attendance Reports', 'route' => 'employees.attendance.report', 'icon' => 'reports', 'permission' => 'employees.attendance.view'],
        ['label' => 'Salary Structures', 'route' => 'employees.salary_structures.index', 'icon' => 'money', 'permission' => 'employees.salary_structures.view'],
        ['label' => 'Leave Requests', 'route' => 'employees.leave.index', 'icon' => 'calendar', 'permission' => 'employees.leave_requests.view'],
        ['label' => 'Payroll', 'route' => 'employees.payroll.index', 'icon' => 'document', 'permission' => 'employees.payroll_runs.view'],
    ];
    $visibleTabs = array_values(array_filter($tabs, fn ($t) => auth()->user()->can($t['permission'])));
@endphp

@if (count($visibleTabs) > 1)
    <div class="mt-6 border-b border-line">
        <nav class="flex flex-wrap gap-1">
            @foreach ($visibleTabs as $tab)
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
@endif
