<x-settings-layout page-title="Backup & Restore">
    <x-page-header title="Backup & Restore" description="Create database snapshots, download them, or restore from a file." icon="database">
        <x-slot name="actions">
            @if (auth()->user()->can('settings.backup.manage'))
                <form method="POST" action="{{ route('settings.backups.create') }}">
                    @csrf
                    <x-button type="submit" icon="database">Create backup now</x-button>
                </form>
            @endif
        </x-slot>
    </x-page-header>

    <div class="mt-6 space-y-6">
        @if (auth()->user()->can('settings.backup.manage'))
            <x-card title="Restore from file" description="Upload a backup .sql file to replace the current database. This overwrites all existing data — proceed with caution.">
                <form method="POST" action="{{ route('settings.backups.restore') }}" enctype="multipart/form-data"
                    onsubmit="return confirm('Restoring will replace the entire database. Continue?');"
                    class="flex flex-col gap-4 sm:flex-row sm:items-end">
                    @csrf
                    <div class="flex-1">
                        <x-input type="file" name="backup" label="Backup file" accept=".sql,.txt" />
                    </div>
                    <x-button type="submit" variant="danger-secondary" icon="refresh">Restore database</x-button>
                </form>
            </x-card>
        @endif

        <x-card title="Available backups" :padding="false">
            @if ($backups->isEmpty())
                <x-empty-state icon="database" title="No backups yet" description="Create your first backup to see it listed here." />
            @else
                <div class="table-wrap !border-0 !rounded-none">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th>Filename</th>
                                <th>Size</th>
                                <th>Created</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($backups as $backup)
                                <tr>
                                    <td class="font-medium text-ink">{{ $backup['name'] }}</td>
                                    <td class="text-ink-soft">{{ number_format($backup['size'] / 1024, 1) }} KB</td>
                                    <td class="text-ink-soft">{{ \Carbon\Carbon::createFromTimestamp($backup['modified'])->diffForHumans() }}</td>
                                    <td>
                                        @if (auth()->user()->can('settings.backup.manage'))
                                            <div class="flex items-center justify-end gap-1">
                                                <a href="{{ route('settings.backups.download', $backup['name']) }}" class="btn-ghost btn-icon btn-sm" title="Download">
                                                    <x-icon name="download" class="size-4" />
                                                </a>
                                                <form method="POST" action="{{ route('settings.backups.destroy', $backup['name']) }}"
                                                    onsubmit="return confirm('Delete this backup?');">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn-ghost btn-icon btn-sm text-rose-500" title="Delete">
                                                        <x-icon name="trash" class="size-4" />
                                                    </button>
                                                </form>
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-card>
    </div>
</x-settings-layout>
