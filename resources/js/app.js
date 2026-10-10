import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';
import { Chart, registerables } from 'chart.js';

window.Alpine = Alpine;
window.Chart = Chart;
Chart.register(...registerables);

// This file is an ES module, so it runs after inline <script> blocks in the body
// have already queued their charts. Flush the queue now that Chart is available.
window.__cbFlushCharts = function () {
    if (!window.__cbRenderCharts) return;
    var queued = window.__cbRenderCharts;
    window.__cbRenderCharts = [];
    queued.forEach(function (fn) {
        try {
            fn();
        } catch (error) {
            console.error('[chart] render failed', error);
        }
    });
};
window.__cbFlushCharts();

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

    /**
     * Global search box in the topbar.
     *
     * Queries /search/api, which scopes results server-side to the permissions
     * and enabled modules of the signed-in user, so this component never has to
     * decide what the user may see — it only renders what it is given.
     */
    Alpine.data('globalSearch', (config) => ({
        endpoint: config.endpoint,
        resultsUrl: config.resultsUrl,
        q: '',
        open: false,
        loading: false,
        results: [],
        // Guards against a slow earlier request overwriting a newer one.
        requestId: 0,
        timer: null,

        init() {
            this.$watch('q', () => this.schedule());
        },

        schedule() {
            clearTimeout(this.timer);

            if (this.q.trim().length < 2) {
                this.results = [];
                this.loading = false;
                this.open = this.q.trim().length > 0;
                return;
            }

            this.open = true;
            this.loading = true;

            this.timer = setTimeout(() => this.fetchResults(), 220);
        },

        async fetchResults() {
            const id = ++this.requestId;
            const term = this.q.trim();

            try {
                const response = await fetch(`${this.endpoint}?q=${encodeURIComponent(term)}`, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });

                if (!response.ok) throw new Error(`Search failed (${response.status})`);

                const data = await response.json();

                if (id !== this.requestId) return;

                this.results = data.results || [];
            } catch (e) {
                if (id !== this.requestId) return;
                this.results = [];
            } finally {
                if (id === this.requestId) this.loading = false;
            }
        },

        submit() {
            if (this.q.trim().length < 2) return;
            window.location.href = `${this.resultsUrl}?q=${encodeURIComponent(this.q.trim())}`;
        },

        select(result) {
            if (result.url) {
                window.location.href = result.url;
            }
        },

        /**
         * Escape the text, then wrap the matched term in <mark>.
         *
         * Built by walking the original string rather than escaping then
         * re-inserting, so a record whose name contains markup cannot inject
         * HTML into the dropdown.
         */
        highlight(text) {
            if (!text) return '';

            const term = this.q.trim();

            if (term.length < 2) {
                return this.escape(text);
            }

            const pattern = new RegExp(this.escapeRegExp(term), 'ig');
            let html = '';
            let lastIndex = 0;

            text.replace(pattern, (match, offset) => {
                html += this.escape(text.slice(lastIndex, offset));
                html += `<mark>${this.escape(match)}</mark>`;
                lastIndex = offset + match.length;
                return match;
            });

            return html + this.escape(text.slice(lastIndex));
        },

        escapeRegExp(value) {
            return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        },

        escape(value) {
            return document.createTextNode(String(value)).textContent;
        },

        handleKeydown(event) {
            if (event.key === 'Escape') {
                this.open = false;
            }
        },

        focusInput() {
            this.$refs.searchInput?.focus();
        },
    }));

    Alpine.data('currencyFromEntity', () => ({        initCurrencySelect() {
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

    /**
     * Guards a payment form against an amount above the selected invoice.
     *
     * Overpaying is allowed — the surplus is kept as customer credit and spent on
     * a later invoice — but it has to be a decision rather than a typo. This
     * states the split in the operator's own figures before anything is saved,
     * and only then sets the flag the server checks.
     *
     * The server refuses an unconfirmed overpayment regardless of what this does,
     * so dismissing the dialog is the correct outcome here and a bypass is not
     * possible.
     */
    Alpine.data('paymentOverpayment', () => ({
        confirmed: false,

        /** Outstanding balance of the invoice currently chosen, or 0 for none. */
        balance() {
            const sel = this.$el.querySelector('[name="invoice_id"]');
            const raw = sel?.selectedOptions[0]?.dataset?.balance;
            return raw === undefined || raw === '' ? 0 : Number(raw);
        },

        amount() {
            const raw = this.$el.querySelector('[name="amount"]')?.value;
            return raw === undefined || raw === '' ? 0 : Number(raw);
        },

        /** The part that settles the bill; the rest becomes customer credit. */
        applied() {
            return Math.min(this.amount(), this.balance());
        },

        surplus() {
            return Math.max(0, this.amount() - this.balance());
        },

        isOverpayment() {
            return this.surplus() > 0.005;
        },

        format(value) {
            return new Intl.NumberFormat(undefined, {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            }).format(value);
        },

        /** Ask first, then set the flag. A cancel leaves the form untouched. */
        confirm(event) {
            if (!this.isOverpayment()) {
                this.confirmed = false;
                return true;
            }

            const applied = this.format(this.applied());
            const surplus = this.format(this.surplus());

            const ok = window.confirm(
                `This payment is more than the invoice is owed.\n\n`
                + `  ${applied} settles the invoice.\n`
                + `  ${surplus} will be held as credit on the customer's account\n`
                + `  and deducted from their next invoice.\n\n`
                + `Continue?`
            );

            this.confirmed = ok;

            if (!ok) {
                event.preventDefault();
            }

            return ok;
        },
    }));

    /**
     * The trigger button and the overlay are two separate Alpine roots — the
     * button sits inline in the page header, the overlay is pushed to a stack
     * outside <main> so printing can hide the form without hiding the document.
     * They are therefore coordinated by a document-level event rather than by
     * sharing one `show` property, which they no longer can.
     *
     * A single event name is used in both directions:
     *   'document-preview:open'  — show the overlay
     *   'document-preview:close' — hide it
     * Escape and the Close button dispatch 'close'. Any instance may receive an
     * event it did not originate, so each guards on its own current state to
     * avoid a refresh loop.
     */
    const previewEvent = (name) => `document-preview:${name}`;

    Alpine.data('documentPreview', (config) => ({
        show: false,
        /** Set while applying a change that arrived from the other root. */
        _remote: false,
        type: config.type || 'Document',
        /**
         * The optional manual / external number. Read live from the form so the
         * preview shows both numbers exactly as the saved document will, rather
         * than a placeholder that quietly hides the field from the user.
         */
        referenceNumber: '',
        /**
         * The panel is a full-viewport overlay, so the party heading has to
         * describe whoever the document is for. It used to be hardcoded
         * "Bill to", which is wrong on every supplier-side document
         * (purchase quote/order/invoice, debit note).
         */
        partyLabel: config.partyLabel || 'Bill to',
        /** Delivery notes carry no money, so they get a different closing line. */
        footer: config.footer || 'Thank you for your business.',
        /** Delivery notes have no price/discount/tax columns at all. */
        pricing: config.pricing !== false,
        /** Only documents with a due date show one. */
        showDueDate: config.showDueDate !== false,
        customerName: config.customerName || '',
        issueDate: config.issueDate || '',
        dueDate: config.dueDate || '',
        currency: config.currency || 'USD',
        notes: config.notes || '',
        items: [],
        /**
         * The document form this previews.
         *
         * The preview button lives in the page header, which is a *sibling* of
         * <main>, so `closest('form')` from here always returns null — it only
         * worked by accident via the document.querySelector fallback below.
         * That fallback is not safe either: the layout renders a logout form in
         * the header, so a bare querySelector('form') can latch onto the wrong
         * form entirely. Select by the fields a document form actually has.
         */
        targetForm() {
            if (this._form && document.contains(this._form)) {
                return this._form;
            }

            const selector = 'form:has([name*="[qty]"]), form:has([name="issue_date"]), form:has([name="invoice_date"]), form:has([name="order_date"]), form:has([name="quote_date"]), form:has([name="delivery_date"]), form:has([name="bill_date"]), form:has([name="payment_date"])';

            this._form = document.querySelector(selector);

            return this._form;
        },
        init() {
            const isTrigger = ! config.isOverlay;

            // Re-read the form on every open, and keep it live while open, so
            // typing a line item or changing the customer is reflected without
            // having to press Refresh.
            this.$watch('show', (isOpen) => {
                // The body class is what the print stylesheet keys off, and the
                // overlay lives outside the app shell so hiding the shell cannot
                // take the document with it. Both roots toggle it, which is
                // idempotent.
                document.body.classList.toggle('document-preview-open', isOpen);

                if (! isTrigger) {
                    if (isOpen) {
                        this.refreshPreview();
                        this.startSync();
                    } else {
                        this.stopSync();
                    }
                }

                // Announce the change to the other root. The trigger owns the
                // button label and the overlay owns the Close button and Escape,
                // so a change made in one must reach the other or the label is
                // left reading "Hide preview" after the panel is gone.
                //
                // The remote flag stops it echoing straight back: both roots
                // listen *and* broadcast, so without it the two would trade
                // events indefinitely.
                if (this._remote) {
                    this._remote = false;

                    return;
                }

                document.dispatchEvent(
                    new CustomEvent(previewEvent(isOpen ? 'open' : 'close'))
                );
            });

            if (isTrigger) {
                return;
            }

            const receive = (isOpen) => {
                if (this.show === isOpen) {
                    return;
                }

                this._remote = true;
                this.show = isOpen;
            };

            document.addEventListener(previewEvent('open'), () => receive(true));
            document.addEventListener(previewEvent('close'), () => receive(false));
        },
        printPreview() {
            this.refreshPreview();
            window.print();
        },
        startSync() {
            const form = this.targetForm();
            if (!form || this._bound) {
                return;
            }

            this._bound = true;
            this._handler = () => this.refreshPreview();
            form.addEventListener('input', this._handler);
            form.addEventListener('change', this._handler);
        },
        stopSync() {
            const form = this._form;

            if (form && this._handler) {
                form.removeEventListener('input', this._handler);
                form.removeEventListener('change', this._handler);
            }

            this._bound = false;
            this._handler = null;
        },
        refreshPreview() {
            const form = this.targetForm();
            if (!form) return;

            const custSel = form.querySelector('[name=customer_id], [name=supplier_id]');
            if (custSel && custSel.selectedOptions[0]) {
                this.customerName = custSel.selectedOptions[0].text
                    .replace('— Select customer —', '')
                    .replace('— Select supplier —', '')
                    .replace('— None —', '')
                    .trim();
            }

            // Date inputs render as yyyy-mm-dd; show them the way the company
            // formats dates elsewhere instead of leaking the raw ISO string.
            const issueInput = form.querySelector('[name=issue_date], [name=invoice_date], [name=order_date], [name=quote_date], [name=delivery_date], [name=bill_date]');
            if (issueInput) this.issueDate = this.formatDate(issueInput.value);

            const dueInput = form.querySelector('[name=due_date]');
            if (dueInput) this.dueDate = this.formatDate(dueInput.value);

            const curSel = form.querySelector('[name=currency]');
            if (curSel) {
                this.currency = curSel.value || config.fallbackCurrency || 'USD';
            }

            const notesInput = form.querySelector('[name=notes]');
            if (notesInput) this.notes = notesInput.value;

            const refInput = form.querySelector('[name=reference_number]');
            this.referenceNumber = refInput ? refInput.value.trim() : '';

            this.items = this.readLineItems(form);
        },
        /**
         * Read the line-item editor's rows.
         *
         * Rows are collected by parsing the index out of each input's name
         * (`items[2][qty]`) and grouping on it. Matching five separate
         * querySelectorAll lists by array position is what produced wrong
         * totals: any page where those lists differ in length or order — an
         * extra qty field, a non-monetary editor, a row mid-render — silently
         * pairs one row's qty with another row's unit price. That is how
         * 10 x 6.50 displayed as 70.
         *
         * Money is rounded per line to 2dp, matching how the server stores and
         * totals the same figures, so the preview cannot disagree with the saved
         * invoice by a fraction of a unit.
         */
        readLineItems(form) {
            const byIndex = new Map();

            const record = (index, field, value) => {
                if (! byIndex.has(index)) {
                    byIndex.set(index, { index });
                }

                byIndex.get(index)[field] = value;
            };

            form.querySelectorAll('input[name], select[name], textarea[name]').forEach((el) => {
                const match = /^items\[(\d+)\]\[(\w+)\]$/.exec(el.name || '');

                if (! match) {
                    return;
                }

                const index = Number(match[1]);
                const field = match[2];

                if (field === 'description' || field === 'product_id') {
                    record(index, field, el.value);
                } else {
                    record(index, field, parseFloat(el.value) || 0);
                }
            });

            const round = (v) => Math.round((v + Number.EPSILON) * 100) / 100;

            return [...byIndex.values()]
                .sort((a, b) => a.index - b.index)
                .map((row) => {
                    const qty = row.qty || 0;
                    const price = row.unit_price || 0;
                    const disc = row.discount_percent || 0;
                    const tax = row.tax_percent || 0;

                    // A row the user has not touched yet is still in the DOM;
                    // listing it as a zero line reads as a phantom invoice row.
                    const desc = row.description || '';
                    const touched = desc !== '' || qty !== 0 || price !== 0;

                    if (! touched) {
                        return null;
                    }

                    const gross = round(qty * price);
                    const net = round(gross * (1 - disc / 100));
                    const taxAmt = round(net * (tax / 100));

                    return {
                        description: desc,
                        qty, unit_price: price, discount_percent: disc, tax_percent: tax,
                        gross, discount: round(gross - net),
                        net, tax: taxAmt, total: round(net + taxAmt),
                    };
                })
                .filter(Boolean);
        },
        formatDate(value) {
            if (!value) return '';

            const [y, m, d] = value.split('-').map(Number);
            if (!y || !m || !d) return value;

            const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

            return `${d} ${months[m - 1]} ${y}`;
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
            this.items.push({ product_id: '', account_id: '', description: '', qty: 1, unit_price: 0, discount_percent: 0, tax_percent: 0 });
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

/**
 * Stop a form being submitted twice.
 *
 * A double click on Save — or a user hitting Enter twice on a slow connection —
 * posts the same document twice. For an invoice that means two documents with two
 * numbers; for a payment, two receipts against one invoice. The damage is not
 * visible until someone reconciles at month end, by which point it is expensive.
 *
 * This is installed once, globally, rather than as an `x-on:submit` on every form:
 * there are hundreds of forms in this application and a per-form handler is exactly
 * the kind of thing that gets forgotten on the four hundredth screen. One listener
 * covers all of them, including forms added later.
 *
 * The button is disabled and marked `aria-busy` so the state is visible to a
 * screen reader too, and the guard is keyed on the form rather than the button so
 * a form with several submit buttons is still protected once any of them is used.
 *
 * `form.requestSubmit()` is not called again -- the browser has already queued the
 * navigation, and this only closes the door behind it.
 */
document.addEventListener('submit', (event) => {
    const form = event.target;

    if (!(form instanceof HTMLFormElement)) return;
    if (form.dataset.submittingGuard === 'off') return;

    // Already submitted once: block the repeat.
    if (form.dataset.submitting === '1') {
        event.preventDefault();
        event.stopImmediatePropagation();
        return;
    }

    form.dataset.submitting = '1';

    form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach((button) => {
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
    });

    // A form that never navigates (a fetch submit, or a validation failure that
    // re-renders in place) must not stay locked forever.
    window.setTimeout(() => {
        delete form.dataset.submitting;
        form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach((button) => {
            button.disabled = false;
            button.removeAttribute('aria-busy');
        });
    }, 8000);
}, true);

Alpine.start();
