<button {{ $attributes->merge(['type' => 'button', 'class' => 'btn-danger btn-md']) }}>
    {{ $slot }}
</button>
