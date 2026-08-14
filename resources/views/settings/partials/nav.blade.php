@php
    $tabs = [
        ['label' => 'Company Profile', 'route' => 'settings.company', 'icon' => 'company', 'permission' => 'settings.company.view'],
        ['label' => 'Currencies', 'route' => 'settings.currencies', 'icon' => 'money', 'permission' => 'settings.currencies.view'],
        ['label' => 'Modules', 'route' => 'settings.modules', 'icon' => 'modules', 'permission' => 'settings.modules.view'],
        ['label' => 'Notification Rules', 'route' => 'settings.notification-rules', 'icon' => 'bell', 'permission' => 'settings.notifications.view'],
        ['label' => 'Users', 'route' => 'settings.users.index', 'icon' => 'users', 'permission' => 'settings.users.view'],
        ['label' => 'Roles & Permissions', 'route' => 'settings.roles.index', 'icon' => 'roles', 'permission' => 'settings.roles.view'],
        ['label' => 'Backup & Restore', 'route' => 'settings.backups', 'icon' => 'database', 'permission' => 'settings.backup.view'],
        ['label' => 'Audit Log', 'route' => 'settings.audit-log', 'icon' => 'audit', 'permission' => 'settings.audit.view'],
        ['label' => 'Mail Server', 'route' => 'settings.mail', 'icon' => 'mail', 'permission' => 'settings.mail.view'],
    ];
    $visibleTabs = array_values(array_filter($tabs, fn ($t) => auth()->user()->can($t['permission'])));
@endphp

@if (count($visibleTabs) > 1)
    <div class="mb-6 overflow-x-auto">
        <nav class="flex min-w-max items-center gap-1 rounded-xl border border-line bg-surface p-1 no-scrollbar">
            @foreach ($visibleTabs as $tab)
                <a
                    href="{{ route($tab['route']) }}"
                    class="{{ request()->routeIs($tab['route']) || request()->routeIs($tab['route'].'*') ? 'bg-primary/10 text-primary' : 'text-ink-soft hover:bg-surface-muted hover:text-ink' }} flex items-center gap-2 rounded-lg px-3 py-1.5 text-sm font-medium transition-colors"
                >
                    <x-icon :name="$tab['icon']" class="size-4" />
                    {{ $tab['label'] }}
                </a>
            @endforeach
        </nav>
    </div>
@endif
