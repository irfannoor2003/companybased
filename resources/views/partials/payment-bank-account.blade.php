@php
    $pbLabel = $pbLabel ?? 'Received into account';
    $pbSelected = $pbSelected ?? null;
    $pbMethods = $pbMethods ?? 'bank_transfer,cheque';
@endphp

<div class="payment-bank-field" data-bank-methods="{{ $pbMethods }}">
    <x-select name="bank_account_id" :label="$pbLabel">
        <option value="">— Select account —</option>
        @foreach ($bankAccounts as $pbAccount)
            <option value="{{ $pbAccount->id }}" @selected((string) $pbSelected === (string) $pbAccount->id)>{{ $pbAccount->name }}@if($pbAccount->bank_name) · {{ $pbAccount->bank_name }}@endif</option>
        @endforeach
    </x-select>
</div>

<script>
(function () {
    var fields = document.querySelectorAll('.payment-bank-field');
    for (var i = 0; i < fields.length; i++) {
        (function (field) {
            var form = field.closest('form');
            var method = form ? form.querySelector('select[name="method"]') : null;
            var select = field.querySelector('select');
            if (!method || !select) {
                return;
            }
            var bankMethods = (field.getAttribute('data-bank-methods') || 'bank_transfer,cheque').split(',');
            var sync = function () {
                var visible = bankMethods.indexOf(method.value) !== -1;
                field.style.display = visible ? '' : 'none';
                select.disabled = !visible;
            };
            method.addEventListener('change', sync);
            sync();
        })(fields[i]);
    }
})();
</script>