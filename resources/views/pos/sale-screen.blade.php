<x-app-layout :pageTitle="'POS Sale Screen'">
    <x-slot name="header">
        <x-page-header title="Sale Screen" description="Quick point-of-sale checkout from the product catalogue." icon="pos">
            <x-slot name="actions">
                <x-badge :color="$openShift ? 'success' : 'danger'">{{ $openShift ? 'Shift '.$openShift->shift_number.' open' : 'No open shift' }}</x-badge>
            </x-slot>
        </x-page-header>
    </x-slot>

    @include('pos._tabs')

    @if (! $openShift)
        <div class="mt-6">
            <x-alert type="warning" title="No open shift">
                You need an open shift before you can make sales. Open one from the <a href="{{ route('pos.shifts.index') }}" class="font-medium underline">Shifts</a> page.
            </x-alert>
        </div>
    @endif

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-5">
        <div class="lg:col-span-3">
            <x-card :padding="false">
                <div class="flex flex-wrap items-center gap-3 border-b border-line px-5 py-4">
                    <div class="min-w-[200px] flex-1">
                        <x-input name="q" label="Search products" placeholder="Name, SKU or barcode…" leadingIcon="search" size="sm"
                            value="{{ request('q') }}" form="product-filter" />
                    </div>
                    <form id="product-filter" method="GET" action="{{ route('pos.sale_screen.index') }}" class="flex gap-2">
                        @if (request()->filled('q'))
                            <input type="hidden" name="q" value="{{ request('q') }}">
                        @endif
                    </form>
                </div>

                @if ($products->isEmpty())
                    <x-empty-state icon="pos" title="No products" description="Enable catalog products to use the sale screen." />
                @else
                    <div class="grid grid-cols-2 gap-3 p-5 sm:grid-cols-3 xl:grid-cols-4">
                        @foreach ($products as $product)
                            <button type="button" data-add-product
                                data-name="{{ $product->name }}"
                                data-price="{{ number_format((float) $product->retail_price, 2, '.', '') }}"
                                data-id="{{ $product->id }}"
                                class="group rounded-xl border border-line bg-surface-muted/40 p-4 text-left transition hover:border-primary/50 hover:bg-primary/5">
                                <span class="block truncate text-sm font-semibold text-ink">{{ $product->name }}</span>
                                <span class="mt-1 block text-sm font-bold text-primary">{{ money($product->retail_price) }}</span>
                                @if ($product->sku)
                                    <span class="mt-1 block text-xs text-ink-faint">{{ $product->sku }}</span>
                                @endif
                            </button>
                        @endforeach
                    </div>
                @endif
            </x-card>
        </div>

        <div class="lg:col-span-2">
            <x-card :padding="false">
                <div class="border-b border-line px-5 py-4">
                    <h3 class="text-sm font-semibold text-ink">Current sale</h3>
                </div>

                @if (! $openShift)
                    <div class="px-5 py-10 text-center text-sm text-ink-faint">Open a shift to start selling.</div>
                @else
                    <form method="POST" action="{{ route('pos.sale_screen.store') }}" class="flex flex-col">
                        @csrf

                        <div class="px-5 py-4">
                            <x-input name="customer_name" label="Customer" placeholder="Walk-in customer" value="{{ old('customer_name') }}" size="sm" />
                        </div>

                        <div class="px-5">
                            <div id="cart-items" class="space-y-2">
                                <p class="py-6 text-center text-sm text-ink-faint">No items yet — click a product to add.</p>
                            </div>
                        </div>

                        <div class="mt-4 space-y-3 border-t border-line px-5 py-4">
                            <div class="flex justify-between text-sm text-ink-soft">
                                <span>Subtotal</span>
                                <span id="cart-subtotal" class="font-medium text-ink">GHS 0.00</span>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <x-input name="discount" label="Discount" type="number" step="0.01" min="0" size="sm" placeholder="0.00" value="{{ old('discount', 0) }}" />
                                <x-input name="tax" label="Tax" type="number" step="0.01" min="0" size="sm" placeholder="0.00" value="{{ old('tax', 0) }}" />
                            </div>
                            <div class="flex justify-between text-sm font-semibold text-ink">
                                <span>Total</span>
                                <span id="cart-total">GHS 0.00</span>
                            </div>
                            <x-select name="payment_method_id" label="Payment method" size="sm">
                                <option value="">—</option>
                                @foreach ($paymentMethods as $method)
                                    <option value="{{ $method->id }}" @selected(old('payment_method_id') == $method->id)>{{ $method->name }}</option>
                                @endforeach
                            </x-select>
                            <x-input name="amount_paid" label="Amount paid" type="number" step="0.01" min="0" size="sm" placeholder="0.00" value="{{ old('amount_paid') }}" />
                            <div class="flex justify-between text-sm text-ink-soft">
                                <span>Change due</span>
                                <span id="cart-change" class="font-medium text-ink">GHS 0.00</span>
                            </div>
                            <x-button type="submit" class="w-full" icon="check" size="lg">Charge</x-button>
                        </div>
                    </form>
                @endif
            </x-card>
        </div>
    </div>

    <script>
        (function () {
            const items = new Map();

            function money(n) {
                return 'GHS ' + Number(n).toFixed(2);
            }

            function recalc() {
                let subtotal = 0;
                document.querySelectorAll('#cart-items [data-line-total]').forEach(el => {
                    subtotal += parseFloat(el.dataset.lineTotal || 0);
                });

                const discount = parseFloat(document.querySelector('input[name="discount"]')?.value || 0) || 0;
                const tax = parseFloat(document.querySelector('input[name="tax"]')?.value || 0) || 0;
                const total = Math.max(subtotal - discount + tax, 0);
                const paid = parseFloat(document.querySelector('input[name="amount_paid"]')?.value || 0) || 0;
                const change = Math.max(paid - total, 0);

                document.getElementById('cart-subtotal').textContent = money(subtotal);
                document.getElementById('cart-total').textContent = money(total);
                document.getElementById('cart-change').textContent = money(change);
            }

            function render() {
                const container = document.getElementById('cart-items');
                container.innerHTML = '';

                if (items.size === 0) {
                    container.innerHTML = '<p class="py-6 text-center text-sm text-ink-faint">No items yet — click a product to add.</p>';
                    return;
                }

                items.forEach((item, key) => {
                    const row = document.createElement('div');
                    row.className = 'flex items-center gap-2 rounded-lg border border-line px-3 py-2';
                    row.innerHTML = `
                        <div class="min-w-0 flex-1">
                            <input type="hidden" name="items[${key}][product_id]" value="${item.id}">
                            <input type="hidden" name="items[${key}][name]" value="${item.name}">
                            <input type="hidden" name="items[${key}][qty]" value="${item.qty}">
                            <input type="hidden" name="items[${key}][price]" value="${item.price}">
                            <span class="block truncate text-sm font-medium text-ink">${item.name}</span>
                            <span class="block text-xs text-ink-faint">${money(item.price)} each</span>
                        </div>
                        <input type="number" data-qty min="0.001" step="any" value="${item.qty}"
                            class="w-16 rounded-lg border border-line bg-surface-muted px-2 py-1 text-right text-sm">
                        <span class="w-20 text-right text-sm font-semibold text-ink" data-line-total="${(item.qty * item.price).toFixed(2)}">${money(item.qty * item.price)}</span>
                        <button type="button" data-remove class="text-danger hover:opacity-70">
                            <x-icon name="trash" class="size-4" />
                        </button>
                    `;
                    container.appendChild(row);

                    const qtyInput = row.querySelector('[data-qty]');
                    qtyInput.addEventListener('change', () => {
                        const qty = parseFloat(qtyInput.value) || 0;
                        if (qty <= 0) { items.delete(key); render(); return; }
                        item.qty = qty;
                        row.querySelector('[data-line-total]').dataset.lineTotal = (item.qty * item.price).toFixed(2);
                        row.querySelector('[data-line-total]').textContent = money(item.qty * item.price);
                        row.querySelector('[name="items[' + key + '][qty]"]').value = qty;
                        recalc();
                    });

                    row.querySelector('[data-remove]').addEventListener('click', () => {
                        items.delete(key);
                        render();
                    });
                });

                recalc();
            }

            document.querySelectorAll('[data-add-product]').forEach(btn => {
                btn.addEventListener('click', () => {
                    const id = btn.dataset.id;
                    const price = parseFloat(btn.dataset.price) || 0;

                    if (items.has(id)) {
                        items.get(id).qty += 1;
                    } else {
                        items.set(id, { id: id, name: btn.dataset.name, price: price, qty: 1 });
                    }

                    render();
                });
            });

            ['discount', 'tax', 'amount_paid'].forEach(name => {
                document.querySelector(`input[name="${name}"]`)?.addEventListener('input', recalc);
            });

            render();
        })();
    </script>
</x-app-layout>