<button {{ $attributes->merge(['type' => 'button', 'class' => 'btn-secondary btn-md']) }}>
    {{ $slot }}
</button>
