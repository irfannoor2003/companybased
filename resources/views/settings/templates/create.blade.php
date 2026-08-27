<x-settings-layout page-title="Create Template">
    <x-page-header title="Create Document Template" description="Design a new template for your documents." icon="document">
        <x-slot name="actions">
            <x-button href="{{ route('settings.templates.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
        </x-slot>
    </x-page-header>

    <form method="POST" action="{{ route('settings.templates.store') }}" class="mt-6 max-w-3xl space-y-6">
        @csrf

        <x-card title="Template Details" description="Choose the document type and basic settings.">
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <x-input name="name" label="Template name" required placeholder="e.g. Modern Invoice" value="{{ old('name') }}" />
                <x-select name="type" label="Document type" required>
                    @foreach (['invoice' => 'Invoice', 'quote' => 'Quote', 'order' => 'Order', 'delivery_note' => 'Delivery Note', 'credit_note' => 'Credit Note', 'purchase_order' => 'Purchase Order', 'purchase_invoice' => 'Purchase Invoice', 'receipt' => 'Receipt'] as $val => $lbl)
                        <option value="{{ $val }}" @selected(old('type') === $val)>{{ $lbl }}</option>
                    @endforeach
                </x-select>
                <div class="sm:col-span-2">
                    <x-textarea name="description" label="Description" placeholder="Brief description of this template..." rows="2">{{ old('description') }}</x-textarea>
                </div>
            </div>
        </x-card>

        <x-card title="Colors" description="Customize the primary, accent and text colors used in the document.">
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-3">
                <div>
                    <label class="field-label">Primary color</label>
                    <div class="flex items-center gap-2">
                        <input type="color" name="primary_color" value="{{ old('primary_color', '#4f46e5') }}" class="h-9 w-14 rounded border border-line cursor-pointer">
                        <x-input name="primary_color" value="{{ old('primary_color', '#4f46e5') }}" class="flex-1" />
                    </div>
                </div>
                <div>
                    <label class="field-label">Accent color</label>
                    <div class="flex items-center gap-2">
                        <input type="color" name="accent_color" value="{{ old('accent_color', '#0ea5e9') }}" class="h-9 w-14 rounded border border-line cursor-pointer">
                        <x-input name="accent_color" value="{{ old('accent_color', '#0ea5e9') }}" class="flex-1" />
                    </div>
                </div>
                <div>
                    <label class="field-label">Text color</label>
                    <div class="flex items-center gap-2">
                        <input type="color" name="text_color" value="{{ old('text_color', '#1f2937') }}" class="h-9 w-14 rounded border border-line cursor-pointer">
                        <x-input name="text_color" value="{{ old('text_color', '#1f2937') }}" class="flex-1" />
                    </div>
                </div>
            </div>
        </x-card>

        <x-card title="Layout" description="Configure the header alignment and visibility options.">
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-3">
                <x-select name="header_position" label="Header position">
                    @foreach (['left' => 'Left', 'center' => 'Center', 'right' => 'Right'] as $val => $lbl)
                        <option value="{{ $val }}" @selected(old('header_position', 'left') === $val)>{{ $lbl }}</option>
                    @endforeach
                </x-select>
                <div class="flex items-center gap-3 pt-6">
                    <input type="checkbox" name="show_logo" value="1" @checked(old('show_logo', true)) class="rounded border-line text-primary focus:ring-primary">
                    <label class="text-sm text-ink">Show company logo</label>
                </div>
                <div class="flex items-center gap-3 pt-6">
                    <input type="checkbox" name="show_tax" value="1" @checked(old('show_tax', true)) class="rounded border-line text-primary focus:ring-primary">
                    <label class="text-sm text-ink">Show tax details</label>
                </div>
            </div>
        </x-card>

        <x-card title="Custom Content" description="Add custom HTML header, footer or CSS overrides.">
            <div class="space-y-5">
                <x-textarea name="header_html" label="Custom header HTML" rows="3" placeholder="<p>Custom header content...</p>">{{ old('header_html') }}</x-textarea>
                <x-textarea name="footer_html" label="Custom footer HTML" rows="3" placeholder="<p>Thank you for your business!</p>">{{ old('footer_html') }}</x-textarea>
                <x-textarea name="css" label="Custom CSS overrides" rows="4" placeholder=".brand-name { font-size: 24px; }">{{ old('css') }}</x-textarea>
            </div>
        </x-card>

        <div class="flex items-center gap-3">
            <input type="checkbox" name="is_default" value="1" @checked(old('is_default')) class="rounded border-line text-primary focus:ring-primary">
            <label class="text-sm text-ink">Set as default template for this document type</label>
        </div>

        <div class="flex justify-end gap-3 border-t border-line pt-4">
            <x-button href="{{ route('settings.templates.index') }}" variant="ghost">Cancel</x-button>
            <x-button type="submit" icon="save">Create template</x-button>
        </div>
    </form>
</x-settings-layout>
