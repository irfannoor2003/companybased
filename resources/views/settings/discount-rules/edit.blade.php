<x-settings-layout page-title="Edit Discount Rule">
    <x-page-header title="Edit Discount Rule" description="Update the discount limit settings." icon="discount">
        <x-slot name="actions">
            <x-button href="{{ route('settings.discount-rules.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
        </x-slot>
    </x-page-header>

    <form method="POST" action="{{ route('settings.discount-rules.update', $rule) }}" class="mt-6 max-w-2xl space-y-6">
        @csrf
        @method('PUT')

        <x-card title="Rule Details" description="Define the discount limit and which roles it applies to.">
            <div class="space-y-5">
                <x-input name="name" label="Rule name" required placeholder="e.g. Salesman Max Discount" value="{{ old('name', $rule->name) }}" />
                <x-textarea name="description" label="Description" rows="2" placeholder="Brief description...">{{ old('description', $rule->description) }}</x-textarea>

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-select name="type" label="Discount type" required>
                        @foreach (['percentage' => 'Percentage (%)', 'fixed' => 'Fixed Amount'] as $val => $lbl)
                            <option value="{{ $val }}" @selected(old('type', $rule->type) === $val)>{{ $lbl }}</option>
                        @endforeach
                    </x-select>
                    <x-input name="max_value" label="Maximum value" type="number" step="0.01" min="0" required placeholder="e.g. 25" value="{{ old('max_value', $rule->max_value) }}" hint="Max percentage (0-100) or max fixed amount" />
                </div>

                <x-input name="currency" label="Currency code" maxlength="3" placeholder="USD" value="{{ old('currency', $rule->currency) }}" hint="Required for fixed-amount rules" />

                <div>
                    <label class="field-label">Apply to roles</label>
                    <div class="mt-1 space-y-2">
                        @foreach (['Salesman', 'Employee', 'Inventory Manager'] as $role)
                            <label class="flex items-center gap-2">
                                <input type="checkbox" name="roles[]" value="{{ $role }}" @checked(in_array($role, old('roles', $rule->roles ?? []))) class="rounded border-line text-primary focus:ring-primary">
                                <span class="text-sm text-ink">{{ $role }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $rule->is_active)) class="rounded border-line text-primary focus:ring-primary">
                    <label class="text-sm text-ink">Active</label>
                </div>
            </div>
        </x-card>

        <div class="flex justify-end gap-3 border-t border-line pt-4">
            <x-button href="{{ route('settings.discount-rules.index') }}" variant="ghost">Cancel</x-button>
            <x-button type="submit" icon="save">Update rule</x-button>
        </div>
    </form>
</x-settings-layout>
