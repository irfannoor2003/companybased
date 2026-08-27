import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';

window.Alpine = Alpine;

Alpine.plugin(collapse);

document.addEventListener('alpine:init', () => {
    Alpine.store('toasts', {
        items: [],
        push(message, type = 'success') {
            const id = Math.random().toString(36).slice(2);

            this.items.push({ id, message, type, visible: true });
            this.$nextTick(() => {
                setTimeout(() => this.dismiss(id), 4200);
            });
        },
        dismiss(id) {
            this.items = this.items.filter((toast) => toast.id !== id);
        },
    });

    Alpine.data('currencyFromEntity', () => ({
        initCurrencySelect() {
            const sel = this.$el.querySelector('[name="customer_id"], [name="supplier_id"]');
            if (sel && sel.value) {
                this.syncCurrency(sel);
            }
        },
        syncCurrency(sel) {
            const cur = sel.selectedOptions[0]?.dataset?.currency || '';
            if (cur) {
                const curField = this.$el.querySelector('[name="currency"]');
                if (curField) {
                    curField.value = cur;
                }
            }
        },
    }));

    Alpine.data('documentPreview', (config) => ({
        show: false,
        customerName: config.customerName || '',
        issueDate: config.issueDate || '',
        dueDate: config.dueDate || '',
        currency: config.currency || 'USD',
        notes: config.notes || '',
        items: [],
        refreshPreview() {
            let form = this.$el.closest('form');
            if (!form) {
                form = document.querySelector(
                    'form:has([name^="items"]), form:has([name="issue_date"]), form:has([name="invoice_date"]), form:has([name="order_date"]), form:has([name="quote_date"]), form:has([name="delivery_date"])'
                ) || document.querySelector('form');
            }
            if (!form) return;

            const custSel = form.querySelector('[name=customer_id]');
            if (custSel && custSel.selectedOptions[0]) {
                this.customerName = custSel.selectedOptions[0].text.replace('— Select customer —', '').replace('— None —', '').trim();
            }

            const issueInput = form.querySelector('[name=issue_date]');
            if (issueInput) this.issueDate = issueInput.value;

            const dueInput = form.querySelector('[name=due_date]');
            if (dueInput) this.dueDate = dueInput.value;

            const curSel = form.querySelector('[name=currency]');
            if (curSel && curSel.selectedOptions[0]) {
                this.currency = curSel.value || config.fallbackCurrency || 'USD';
            }

            const notesInput = form.querySelector('[name=notes]');
            if (notesInput) this.notes = notesInput.value;

            this.items = [];
            const descInputs = form.querySelectorAll('[name*="[description]"]');
            const qtyInputs = form.querySelectorAll('[name*="[qty]"]');
            const priceInputs = form.querySelectorAll('[name*="[unit_price]"]');
            const discInputs = form.querySelectorAll('[name*="[discount_percent]"]');
            const taxInputs = form.querySelectorAll('[name*="[tax_percent]"]');

            for (let i = 0; i < qtyInputs.length; i++) {
                const qty = parseFloat(qtyInputs[i]?.value) || 0;
                const price = parseFloat(priceInputs[i]?.value) || 0;
                const disc = parseFloat(discInputs[i]?.value) || 0;
                const tax = parseFloat(taxInputs[i]?.value) || 0;
                const gross = qty * price;
                const net = gross * (1 - disc / 100);
                const taxAmt = net * (tax / 100);

                this.items.push({
                    description: descInputs[i]?.value || '',
                    qty, unit_price: price, discount_percent: disc, tax_percent: tax,
                    gross, discount: gross - net,
                    net, tax: taxAmt, total: net + taxAmt,
                });
            }
        },
        grossTotal() { return this.items.reduce((s, i) => s + i.gross, 0); },
        discountTotal() { return this.items.reduce((s, i) => s + i.discount, 0); },
        hasDiscounts() { return this.items.some((i) => i.discount > 0); },
        subtotal() { return this.items.reduce((s, i) => s + i.net, 0); },
        taxTotal() { return this.items.reduce((s, i) => s + i.tax, 0); },
        grandTotal() { return this.subtotal() + this.taxTotal(); },
        money(v) {
            try { return new Intl.NumberFormat('en', { style: 'currency', currency: this.currency || 'USD' }).format(v); }
            catch { return Number(v).toFixed(2) + ' ' + this.currency; }
        },
    }));

    Alpine.data('salesLineItems', (config) => ({
        currency: config.currency || 'USD',
        products: config.products || {},
        items: config.items || [],
        maxDiscount: config.maxDiscount ?? null,
        discountError: '',
        addRow() {
            this.items.push({ product_id: '', description: '', qty: 1, unit_price: 0, discount_percent: 0, tax_percent: 0 });
        },
        removeRow(i) {
            this.items.splice(i, 1);
        },
        onProductChange(i) {
            const p = this.products[this.items[i].product_id];
            if (p) {
                this.items[i].description = p.description;
                this.items[i].unit_price = p.unit_price;
            }
        },
        validateDiscount(i) {
            if (this.maxDiscount !== null && this.maxDiscount !== undefined) {
                const val = Number(this.items[i].discount_percent) || 0;
                if (val > this.maxDiscount) {
                    this.discountError = 'Discount cannot exceed ' + this.maxDiscount + '%. You entered ' + val + '%.';
                    this.items[i].discount_percent = this.maxDiscount;
                    return false;
                }
            }
            this.discountError = '';
            return true;
        },
        lineGross(i) {
            const it = this.items[i];
            return (Number(it.qty) || 0) * (Number(it.unit_price) || 0);
        },
        lineNet(i) {
            const it = this.items[i];
            const sub = (Number(it.qty) || 0) * (Number(it.unit_price) || 0);
            return sub * (1 - (Number(it.discount_percent) || 0) / 100);
        },
        lineTax(i) {
            return this.lineNet(i) * ((Number(this.items[i].tax_percent) || 0) / 100);
        },
        subtotal() {
            return this.items.reduce((s, _, i) => s + this.lineGross(i), 0);
        },
        discountTotal() {
            return this.items.reduce((s, _, i) => s + (this.lineGross(i) - this.lineNet(i)), 0);
        },
        tax() {
            return this.items.reduce((s, _, i) => s + this.lineTax(i), 0);
        },
        total() {
            return this.subtotal() - this.discountTotal() + this.tax();
        },
        money(v) {
            try {
                return new Intl.NumberFormat('en', { style: 'currency', currency: this.currency || 'USD' }).format(v);
            } catch {
                return Number(v).toFixed(2) + ' ' + this.currency;
            }
        },
    }));

    Alpine.data('supplierLineItems', (config) => ({
        currency: config.currency || 'USD',
        products: config.products || {},
        items: config.items || [],
        addRow() {
            this.items.push({ product_id: '', description: '', qty: 1, unit_price: 0, discount_percent: 0, tax_percent: 0 });
        },
        removeRow(i) {
            this.items.splice(i, 1);
        },
        onProductChange(i) {
            const p = this.products[this.items[i].product_id];
            if (p) {
                this.items[i].description = p.description;
                this.items[i].unit_price = p.unit_price;
            }
        },
        lineGross(i) {
            const it = this.items[i];
            return (Number(it.qty) || 0) * (Number(it.unit_price) || 0);
        },
        lineNet(i) {
            const it = this.items[i];
            const sub = (Number(it.qty) || 0) * (Number(it.unit_price) || 0);
            return sub * (1 - (Number(it.discount_percent) || 0) / 100);
        },
        lineTax(i) {
            return this.lineNet(i) * ((Number(this.items[i].tax_percent) || 0) / 100);
        },
        subtotal() {
            return this.items.reduce((s, _, i) => s + this.lineGross(i), 0);
        },
        discountTotal() {
            return this.items.reduce((s, _, i) => s + (this.lineGross(i) - this.lineNet(i)), 0);
        },
        tax() {
            return this.items.reduce((s, _, i) => s + this.lineTax(i), 0);
        },
        total() {
            return this.subtotal() - this.discountTotal() + this.tax();
        },
        money(v) {
            try {
                return new Intl.NumberFormat('en', { style: 'currency', currency: this.currency || 'USD' }).format(v);
            } catch {
                return Number(v).toFixed(2) + ' ' + this.currency;
            }
        },
    }));

    Alpine.data('reconciliationEditor', (config) => ({
        opening: config.opening,
        statementEnding: config.statementEnding,
        signed: config.signed || {},
        clearedSum() {
            return Object.entries(this.signed).reduce((sum, [id, v]) => {
                const el = document.querySelector('input[name="cleared[' + id + ']"]');
                return sum + (el && el.checked ? v : 0);
            }, 0);
        },
        book() { return this.opening + this.clearedSum(); },
        diff() { return this.statementEnding - this.book(); },
        money(v) {
            try {
                return new Intl.NumberFormat('en', { style: 'currency', currency: config.currency || 'USD' }).format(v);
            } catch {
                return Number(v).toFixed(2) + ' ' + (config.currency || 'USD');
            }
        },
    }));

    Alpine.data('shortCodeSuggest', (suggestUrl) => ({
        auto: false,
        suggestShortCode() {
            const nameField = this.$el.querySelector('[name="company_name"]');
            const codeField = this.$el.querySelector('[name="short_code"]');
            if (!nameField || !codeField) {
                return;
            }
            const name = (nameField.value || '').trim();
            if (!name) {
                return;
            }
            if (!codeField.value || this.auto) {
                fetch(`${suggestUrl}?name=${encodeURIComponent(name)}`)
                    .then((res) => res.json())
                    .then((data) => {
                        if (data.code) {
                            codeField.value = data.code;
                            this.auto = true;
                        }
                    })
                    .catch(() => {});
            }
        },
        markTouched() {
            this.auto = false;
        },
    }));
});

function appShell() {
    return {
        drawerOpen: false,
        collapsed: localStorage.getItem('cb-sidebar') === 'collapsed',
        isDark: window.__cbTheme === 'dark',
        isDesktop: window.innerWidth >= 1024,

        init() {
            this.$watch('collapsed', (value) => {
                localStorage.setItem('cb-sidebar', value ? 'collapsed' : 'expanded');
            });

            const onResize = () => {
                this.isDesktop = window.innerWidth >= 1024;
                if (this.isDesktop) {
                    this.drawerOpen = true;
                }
            };

            onResize();
            window.addEventListener('resize', onResize);
        },

        toggleTheme() {
            this.isDark = !this.isDark;
            document.documentElement.classList.toggle('dark', this.isDark);
            localStorage.setItem('cb-theme', this.isDark ? 'dark' : 'light');
        },
    };
}

window.appShell = appShell;

function permissionMatrix() {
    return {
        toggleModule(module, checked) {
            document.querySelectorAll(`[data-perm-module="${module}"]`).forEach((el) => {
                el.checked = checked;
            });
        },
        checkAllPermissions(checked) {
            document.querySelectorAll('[name="permissions[]"]').forEach((el) => {
                el.checked = checked;
            });
        },
    };
}

window.permissionMatrix = permissionMatrix;

Alpine.start();
