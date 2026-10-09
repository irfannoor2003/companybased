{{--
    The optional manual / external document number.

    Every document has a sequential system number generated on save
    (INV-2026-0001). That number is internal: it drives the counters, the PDF
    filenames and the exports, so it must not be replaced with a typed value.

    This field is for the case where the business already issues documents from
    another system and needs its own number — "INV-OS-4471" — to appear on the
    document, because that is the number the customer and the accountant quote.
    Both numbers are kept side by side and both are shown on the document.
--}}
@props([
    'value' => null,
    'label' => 'Manual number',
    'example' => 'INV-OS-4471',
    'disabled' => false,
])

<div class="sm:col-span-2">
    <x-input
        name="reference_number"
        :label="$label"
        value="{{ old('reference_number', $value) }}"
        :placeholder="$example"
        maxlength="120"
        :disabled="$disabled"
        hint="Optional. Use this only if the document already has a number from another system. The system number is generated automatically and is kept either way. Must be unique."
    />
</div>
