@props([
    'title' => null,
    'description' => null,
    'icon' => null,
])

{{--
    print:hidden on the root: a page title such as "Invoice INV-2026-0007 /
    Acme Traders LLC" is screen furniture. Its ancestor rules hide the sidebar
    and topbar on paper, but this strip is a plain <div> inside <main>, so
    without this it printed above the document.

    Attributes are rendered too: a caller that adds classes (e.g. extra print
    hints) is entitled to see them applied, which is what the show screens
    already pass and which the component used to drop silently.
--}}
<div {{ $attributes->merge(['class' => 'print:hidden flex flex-wrap items-end justify-between gap-4']) }}>
    <div class="flex min-w-0 items-start gap-3">
        @if ($icon)
            <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary-ink">
                <x-icon :name="$icon" class="size-5" />
            </div>
        @endif
        <div class="min-w-0">
            @if ($title)
                <h1 class="text-xl font-bold tracking-tight text-ink sm:text-2xl">{{ $title }}</h1>
            @endif
            @if ($description)
                <p class="mt-1 text-sm text-ink-faint">{{ $description }}</p>
            @endif
        </div>
    </div>

    @isset($actions)
        <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
    @endisset
</div>
