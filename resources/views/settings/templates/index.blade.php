<x-settings-layout page-title="Document Templates">
    <x-page-header title="Document Templates" description="Customize the design of invoices, quotes, orders and other documents." icon="document">
        <x-slot name="actions">
            <x-button href="{{ route('settings.templates.create') }}" icon="plus">New template</x-button>
        </x-slot>
    </x-page-header>

    <div class="mt-6">
        @php $types = ['invoice', 'quote', 'order', 'delivery_note', 'credit_note', 'purchase_order', 'purchase_invoice', 'receipt']; @endphp
        @foreach ($types as $type)
            @php $typeTemplates = $templates->where('type', $type); @endphp
            @if ($typeTemplates->count() > 0 || true)
                <div class="mb-6">
                    <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-ink-soft">{{ ucwords(str_replace('_', ' ', $type)) }}</h3>
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        @forelse ($typeTemplates as $template)
                            <div class="group relative rounded-xl border border-line bg-surface p-4 transition-shadow hover:shadow-md">
                                <div class="mb-3 flex items-start justify-between">
                                    <div class="flex items-center gap-2">
                                        <div class="h-8 w-8 rounded-lg" style="background: {{ $template->colors['primary'] ?? '#4f46e5' }}"></div>
                                        <div>
                                            <p class="font-semibold text-ink">{{ $template->name }}</p>
                                            @if ($template->is_default)
                                                <span class="inline-block rounded bg-primary/10 px-1.5 py-0.5 text-[10px] font-medium text-primary">Default</span>
                                            @endif
                                            @if ($template->is_system)
                                                <span class="inline-block rounded bg-ink-soft/10 px-1.5 py-0.5 text-[10px] font-medium text-ink-soft">System</span>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                                @if ($template->description)
                                    <p class="mb-3 text-xs text-ink-soft">{{ $template->description }}</p>
                                @endif
                                <div class="flex gap-1">
                                    <span class="rounded bg-surface-muted px-1.5 py-0.5 text-[10px] text-ink-soft">{{ strtoupper($template->colors['primary'] ?? '#4f46e5') }}</span>
                                    <span class="rounded bg-surface-muted px-1.5 py-0.5 text-[10px] text-ink-soft">{{ strtoupper($template->colors['accent'] ?? '#0ea5e9') }}</span>
                                    <span class="rounded bg-surface-muted px-1.5 py-0.5 text-[10px] text-ink-soft">Header: {{ $template->layout['header'] ?? 'left' }}</span>
                                </div>
                                <div class="mt-3 flex gap-2 border-t border-line pt-3">
                                    <x-button href="{{ route('settings.templates.edit', $template) }}" size="sm" variant="ghost" icon="edit">Edit</x-button>
                                    @unless ($template->is_system)
                                        <form method="POST" action="{{ route('settings.templates.destroy', $template) }}" onsubmit="return confirm('Delete this template?')">
                                            @csrf
                                            @method('DELETE')
                                            <x-button type="submit" size="sm" variant="ghost" icon="trash" class="text-rose-500">Delete</x-button>
                                        </form>
                                    @endunless
                                </div>
                            </div>
                        @empty
                            <p class="col-span-full rounded-lg border border-dashed border-line py-8 text-center text-sm text-ink-soft">
                                No templates for this type yet.
                            </p>
                        @endforelse
                    </div>
                </div>
            @endif
        @endforeach
    </div>
</x-settings-layout>
