{{--
    Shared form body for both sidebar scopes: an admin editing a role's defaults
    and a user editing their own links. The posted shape is identical:
    `visible[]` for the built-in keys and `custom[i][label|url|icon]` for extras.
--}}
@php
    $isVisible = function (string $key) use ($preferences, $rolePreferences, $scope): bool {
        $own = $preferences->get($key);

        if ($own) {
            return (bool) $own->is_visible;
        }

        if ($scope === 'personal' && $rolePreferences->has($key)) {
            return (bool) $rolePreferences->get($key)->is_visible;
        }

        return true;
    };

    $customLinks = $preferences->filter(fn ($p) => $p->isCustom())
        ->map(fn ($p) => ['label' => $p->label, 'url' => $p->url, 'icon' => $p->icon])
        ->values()
        ->all();
@endphp

<form method="POST" action="{{ $action }}" class="space-y-6" x-data="{
    links: @js($customLinks ?: []),
    icons: ['globe', 'document', 'report', 'mail', 'phone', 'map', 'chart', 'link', 'inbox', 'info'],
}">
    @csrf
    @method('PUT')

    <div class="surface">
        <div class="flex items-start justify-between gap-4 border-b border-line px-5 py-4">
            <div>
                <h3 class="text-sm font-semibold text-ink">Sidebar links</h3>
                <p class="mt-0.5 text-xs text-ink-faint">
                    Tick the links to show. A link you cannot access because of your
                    permissions is hidden automatically, whatever is ticked here.
                </p>
            </div>
        </div>
        <div class="px-5 py-5">
            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($entries as $entry)
                    <label @class([
                        'flex items-center gap-2 rounded-lg border px-3 py-2 text-sm cursor-pointer',
                        'border-primary bg-primary/5 text-ink' => $entry['group'],
                        'border-line text-ink-soft hover:bg-surface-muted' => ! $entry['group'],
                    ])>
                        <input type="checkbox" name="visible[]" value="{{ $entry['key'] }}"
                               class="rounded border-line text-primary-ink focus:ring-primary"
                               @checked($isVisible($entry['key']))>
                        <span class="min-w-0 truncate">{{ $entry['label'] }}</span>
                        @if ($entry['group'])
                            <span class="ml-auto text-[10px] uppercase tracking-wide text-ink-faint">Section</span>
                        @endif
                    </label>
                @endforeach
            </div>
        </div>
    </div>

    <div class="surface">
        <div class="flex items-start justify-between gap-4 border-b border-line px-5 py-4">
            <div>
                <h3 class="text-sm font-semibold text-ink">Custom links</h3>
                <p class="mt-0.5 text-xs text-ink-faint">
                    Add your own shortcuts. Use a path like <code>/reports/grand</code> or a full https:// address.
                </p>
            </div>
            <button type="button" class="btn-secondary btn-sm shrink-0" @click="links.push({ label: '', url: '', icon: 'globe' })">
                Add link
            </button>
        </div>
        <div class="space-y-3 px-5 py-5">
            <template x-if="links.length === 0">
                <p class="rounded-lg border border-dashed border-line px-4 py-5 text-center text-xs text-ink-faint">
                    No custom links. Add one to pin a page here.
                </p>
            </template>
            <template x-for="(link, i) in links" :key="i">
                <div class="grid grid-cols-1 gap-2 rounded-lg border border-line bg-surface-muted/40 p-3 sm:grid-cols-[1fr_1.4fr_8rem_auto]">
                    <div>
                        <label class="field-label">Label</label>
                        <input type="text" class="input" :name="'custom[' + i + '][label]'" x-model="link.label" placeholder="Grand report" maxlength="80">
                    </div>
                    <div>
                        <label class="field-label">URL</label>
                        <input type="text" class="input" :name="'custom[' + i + '][url]'" x-model="link.url" placeholder="/reports/grand" maxlength="500">
                    </div>
                    <div>
                        <label class="field-label">Icon</label>
                        <select class="select-input" :name="'custom[' + i + '][icon]'" x-model="link.icon">
                            <template x-for="icon in icons" :key="icon">
                                <option :value="icon" x-text="icon"></option>
                            </template>
                        </select>
                    </div>
                    <div class="flex items-end justify-end">
                        <button type="button" class="btn-ghost btn-icon btn-sm text-rose-500" title="Remove link"
                                @click="links.splice(i, 1)">
                            <x-icon name="trash" class="size-4" />
                        </button>
                    </div>
                </div>
            </template>
        </div>
    </div>

    <div class="flex flex-wrap items-center gap-3 border-t border-line pt-4">
        <x-button type="submit" icon="save">Save sidebar</x-button>
        @if ($scope === 'personal')
            <a href="{{ route('sidebar.customize.reset') }}"
               class="btn-ghost btn-md"
               onclick="event.preventDefault(); document.getElementById('reset-sidebar').submit();">
                Reset to role defaults
            </a>
        @endif
    </div>
</form>

@if ($scope === 'personal')
    <form id="reset-sidebar" method="POST" action="{{ route('sidebar.customize.reset') }}" class="hidden">
        @csrf
        @method('DELETE')
    </form>
@endif
