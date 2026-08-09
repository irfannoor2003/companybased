@props([
    'presets' => ['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'custom' => 'Custom'],
    'default' => 'monthly',
    'action' => null,
])

<form
    method="GET"
    action="{{ $action ?? request()->url() }}"
    class="flex flex-wrap items-end gap-3"
    x-data="{ period: '{{ request('period', $default) }}' }"
>
    <input type="hidden" name="period" :value="period">

    <div class="flex items-center gap-1 rounded-lg border border-line bg-surface p-1">
        @foreach ($presets as $value => $label)
            <button
                type="button"
                @click="period = '{{ $value }}'"
                class="rounded-md px-3 py-1.5 text-sm font-medium transition-colors"
                :class="period === '{{ $value }}' ? 'bg-primary text-white shadow-sm' : 'text-ink-soft hover:text-ink'"
            >
                {{ $label }}
            </button>
        @endforeach
    </div>

    <div x-show="period === 'custom'" x-transition class="flex flex-wrap items-end gap-3">
        <x-input name="from" label="From" type="date" :value="request('from')" size="sm" />
        <x-input name="to" label="To" type="date" :value="request('to')" size="sm" />
    </div>

    @isset($filters)
        {{ $filters }}
    @endisset

    <x-button type="submit" size="md" icon="reports">Run report</x-button>

    @isset($actions)
        {{ $actions }}
    @endisset
</form>
