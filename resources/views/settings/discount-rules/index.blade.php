<x-settings-layout page-title="Discount Rules">
    <x-page-header title="Discount Rules" description="Set maximum discount limits for sales staff to prevent excessive discounting." icon="discount">
        <x-slot name="actions">
            <x-button href="{{ route('settings.discount-rules.create') }}" icon="plus">New rule</x-button>
        </x-slot>
    </x-page-header>

    <div class="mt-6">
        @forelse ($rules as $rule)
            <x-card class="mb-4">
                <div class="flex items-start justify-between">
                    <div class="flex-1">
                        <div class="flex items-center gap-2">
                            <h3 class="font-semibold text-ink">{{ $rule->name }}</h3>
                            <span class="rounded {{ $rule->is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-ink-soft/10 text-ink-soft' }} px-2 py-0.5 text-xs font-medium">
                                {{ $rule->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </div>
                        @if ($rule->description)
                            <p class="mt-1 text-sm text-ink-soft">{{ $rule->description }}</p>
                        @endif
                        <div class="mt-2 flex flex-wrap gap-2">
                            <span class="rounded bg-surface-muted px-2 py-1 text-xs text-ink-soft">
                                Max: {{ $rule->max_discount_label }}
                            </span>
                            <span class="rounded bg-surface-muted px-2 py-1 text-xs text-ink-soft">
                                Type: {{ ucfirst($rule->type) }}
                            </span>
                            @foreach ($rule->roles as $role)
                                <span class="rounded bg-primary/10 px-2 py-1 text-xs font-medium text-primary">{{ $role }}</span>
                            @endforeach
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <x-button href="{{ route('settings.discount-rules.edit', $rule) }}" size="sm" variant="ghost" icon="edit">Edit</x-button>
                        <form method="POST" action="{{ route('settings.discount-rules.destroy', $rule) }}" onsubmit="return confirm('Delete this rule?')">
                            @csrf
                            @method('DELETE')
                            <x-button type="submit" size="sm" variant="ghost" icon="trash" class="text-rose-500">Delete</x-button>
                        </form>
                    </div>
                </div>
            </x-card>
        @empty
            <div class="rounded-xl border border-dashed border-line py-12 text-center">
                <p class="text-sm text-ink-soft">No discount rules configured yet.</p>
                <x-button href="{{ route('settings.discount-rules.create') }}" size="sm" icon="plus" class="mt-3">Create first rule</x-button>
            </div>
        @endforelse
    </div>

    <x-card title="How discount rules work" class="mt-6">
        <ul class="list-disc space-y-1 pl-5 text-sm text-ink-soft">
            <li>When a Salesman creates a quote or order, the system checks all active rules that apply to their role.</li>
            <li>If the discount on any line item exceeds the maximum allowed, the form will show an error and cannot be submitted.</li>
            <li>Admin and other roles with full permissions are not restricted by these rules.</li>
            <li>You can create separate rules for percentage-based or fixed-amount discounts.</li>
        </ul>
    </x-card>
</x-settings-layout>
