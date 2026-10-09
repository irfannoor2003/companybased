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
            <x-card title="Restore from file" description="Upload a backup .sql file to replace the current database. A safety backup of the current data is taken automatically before anything is overwritten.">
                <form method="POST" action="{{ route('settings.backups.restore') }}" enctype="multipart/form-data"
                    onsubmit="return confirm('Restoring will replace the entire database and sign you out. Continue?');"
                    class="space-y-4">
                    @csrf
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-end">
                        <div class="flex-1">
                            <x-input type="file" name="backup" label="Backup file" accept=".sql,.txt" />
                        </div>
                        <div class="flex-1">
                            <x-input name="confirmation" label="Type RESTORE to confirm" placeholder="RESTORE" autocomplete="off" />
                        </div>
                        <x-button type="submit" variant="danger-secondary" icon="refresh">Restore database</x-button>
                    </div>
                    <p class="text-xs text-ink-faint">
                        A restore cannot be undone from this page. Take a fresh backup first if you are unsure which file to use.
                    </p>
                </form>
            </x-card>
        @endif

        <x-card title="Available backups" description="The newest {{ $retention }} backup(s) are kept on disk; anything older is removed automatically after the next backup." :padding="false">
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
