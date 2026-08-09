<button {{ $attributes->merge(['type' => 'submit', 'class' => 'btn-primary btn-md']) }}>
    {{ $slot }}
</button>
